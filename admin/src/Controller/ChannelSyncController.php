<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use InvalidArgumentException;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Reservation\ChannelSyncResult;
use OceanViewFlats\Domain\Reservation\ChannelSyncStatus;
use OceanViewFlats\Domain\Reservation\InboundChannelSyncServiceInterface;

/**
 * Administrative controller managing inbound OTA (Airbnb) iCalendar feed synchronization,
 * health status reporting, and on-demand manual triggers adhering to ADR 0002.
 */
final class ChannelSyncController
{
    public function __construct(
        private readonly InboundChannelSyncServiceInterface $syncService,
        private readonly ViewRenderer $viewRenderer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * GET /channel-sync/card: Renders the HTMX stat card partial representing live feed health.
     *
     * @param array<string, mixed> $session
     */
    public function card(Request $request, array &$session): Response
    {
        $cardData = self::buildCardViewData(
            syncService: $this->syncService,
            csrfToken: (string) ($session['csrf_token'] ?? '')
        );

        $html = $this->viewRenderer->renderPartial('dashboard/_channel_card.php', $cardData);

        return Response::html($html, 200);
    }

    /**
     * POST /channel-sync: Triggers on-demand manual synchronization for all feeds or a specific property.
     *
     * @param array<string, mixed> $session
     */
    public function sync(Request $request, array &$session): Response
    {
        $rawPropertyId = (string) ($request->getPost('property_id', (string) $request->getQuery('property_id', 'all')));
        $propertyId = trim($rawPropertyId);
        if ($propertyId === '') {
            $propertyId = 'all';
        }

        $force = ($request->getPost('force') === '1'
            || $request->getQuery('force') === '1'
            || $request->getPost('force') === true
            || $request->getQuery('force') === 'true');

        $adminUserId = isset($session['admin_user_id']) ? (int) $session['admin_user_id'] : null;

        /** @var array<string|int, ChannelSyncResult> $results */
        $results = [];
        try {
            if ($propertyId === 'all') {
                $results = $this->syncService->syncAll(force: $force, initiatedBy: 'admin');
                $entityId = 'all';
            } else {
                $singleResult = $this->syncService->sync($propertyId, force: $force, initiatedBy: 'admin');
                $results = [$propertyId => $singleResult];
                $entityId = $propertyId;
            }
        } catch (InvalidArgumentException $e) {
            $cardData = self::buildCardViewData(
                syncService: $this->syncService,
                csrfToken: (string) ($session['csrf_token'] ?? ''),
                notice: [
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ]
            );
            $html = $this->viewRenderer->renderPartial('dashboard/_channel_card.php', $cardData);
            return Response::html($html, 422);
        }

        // Record immutable administrative audit log entry
        $afterPayload = [
            'scope' => $entityId,
            'force' => $force,
            'results' => [],
        ];

        $anyCooldown = false;
        $anyError = false;
        $anyDegraded = false;
        $totalBlockedNights = 0;

        foreach ($results as $prop => $result) {
            $afterPayload['results'][(string) $prop] = [
                'status' => $result->getStatus()->getStatus(),
                'blocked_nights_count' => $result->getBlockedNightsCount(),
                'was_skipped_due_to_cooldown' => $result->wasSkippedDueToCooldown(),
                'message' => $result->getMessage(),
            ];

            if ($result->wasSkippedDueToCooldown()) {
                $anyCooldown = true;
            }
            if ($result->getStatus()->isError()) {
                $anyError = true;
            } elseif ($result->getStatus()->isDegraded()) {
                $anyDegraded = true;
            }
            $totalBlockedNights += $result->getBlockedNightsCount();
        }

        $this->auditLogger->record(
            action: 'channel_sync_manual',
            entityType: 'channel_sync',
            entityId: $entityId,
            before: null,
            after: $afterPayload,
            adminUserId: $adminUserId,
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        // Formulate notice banner
        $notice = null;
        if ($anyCooldown && count($results) === 1) {
            /** @var ChannelSyncResult $firstResult */
            $firstResult = reset($results);
            $notice = [
                'type' => 'info',
                'message' => $firstResult->getMessage() ?? 'Cooldown active. Feed was recently synchronized.',
            ];
        } elseif ($anyCooldown) {
            $notice = [
                'type' => 'info',
                'message' => 'Cooldown active on one or more feeds (minimum 60s). Showing fresh cached calendar.',
            ];
        } elseif ($anyError) {
            $notice = [
                'type' => 'error',
                'message' => 'Synchronization completed with errors on one or more feeds.',
            ];
        } elseif ($anyDegraded) {
            $notice = [
                'type' => 'warning',
                'message' => 'Upstream connection failed on one or more feeds. Cached blocks safely retained.',
            ];
        } else {
            $notice = [
                'type' => 'success',
                'message' => count($results) > 1
                    ? sprintf('All feeds synchronized successfully. %d active blocked nights.', $totalBlockedNights)
                    : sprintf('Feed synchronized successfully. %d active blocked nights.', $totalBlockedNights),
            ];
        }

        $cardData = self::buildCardViewData(
            syncService: $this->syncService,
            csrfToken: (string) ($session['csrf_token'] ?? ''),
            notice: $notice
        );

        $html = $this->viewRenderer->renderPartial('dashboard/_channel_card.php', $cardData);

        return Response::html($html, 200);
    }

    /**
     * Builds standard view data for the dashboard channel stat card.
     *
     * @param array{type: string, message: string}|null $notice
     * @return array<string, mixed>
     */
    public static function buildCardViewData(
        ?InboundChannelSyncServiceInterface $syncService,
        string $csrfToken,
        ?array $notice = null
    ): array {
        /** @var array<string|int, ChannelSyncStatus> $statuses */
        $statuses = $syncService !== null ? $syncService->getAllStatuses() : [];

        $health = self::resolveAggregateHealth($statuses);
        $lastSyncedAt = self::resolveLatestSyncTimestamp($statuses);
        $relativeSyncedTime = self::formatRelativeTime($lastSyncedAt);
        $totalBlockedNights = $syncService !== null ? self::resolveTotalBlockedNights($syncService, $statuses) : 0;

        $badgeText = match ($health) {
            ChannelSyncStatus::STATUS_DEGRADED => 'Degraded',
            ChannelSyncStatus::STATUS_ERROR => 'Error',
            default => 'Healthy',
        };

        $badgeClasses = match ($health) {
            ChannelSyncStatus::STATUS_DEGRADED => 'bg-amber-50 text-amber-700 border-amber-200',
            ChannelSyncStatus::STATUS_ERROR => 'bg-rose-50 text-rose-700 border-rose-200',
            default => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        };

        $badgeDotClass = match ($health) {
            ChannelSyncStatus::STATUS_DEGRADED => 'bg-amber-500',
            ChannelSyncStatus::STATUS_ERROR => 'bg-rose-500',
            default => 'bg-emerald-500',
        };

        $iconBgClass = match ($health) {
            ChannelSyncStatus::STATUS_DEGRADED => 'bg-amber-50',
            ChannelSyncStatus::STATUS_ERROR => 'bg-rose-50',
            default => 'bg-emerald-50',
        };

        $iconTextClass = match ($health) {
            ChannelSyncStatus::STATUS_DEGRADED => 'text-amber-600',
            ChannelSyncStatus::STATUS_ERROR => 'text-rose-600',
            default => 'text-emerald-600',
        };

        $noticeClasses = '';
        if ($notice !== null) {
            $noticeClasses = match ($notice['type']) {
                'error' => 'bg-rose-50 border border-rose-200 text-rose-800',
                'warning' => 'bg-amber-50 border border-amber-200 text-amber-800',
                'info' => 'bg-blue-50 border border-blue-200 text-blue-800',
                default => 'bg-emerald-50 border border-emerald-200 text-emerald-800',
            };
        }

        return [
            'health' => $health,
            'badgeText' => $badgeText,
            'badgeClasses' => $badgeClasses,
            'badgeDotClass' => $badgeDotClass,
            'iconBgClass' => $iconBgClass,
            'iconTextClass' => $iconTextClass,
            'lastSyncedAt' => $lastSyncedAt,
            'relativeSyncedTime' => $relativeSyncedTime,
            'totalBlockedNights' => $totalBlockedNights,
            'syncNotice' => $notice,
            'noticeClasses' => $noticeClasses,
            'csrfToken' => $csrfToken,
        ];
    }

    /**
     * Resolves human-readable relative time from an ISO-8601 timestamp.
     */
    public static function formatRelativeTime(?string $isoTimestamp, ?int $nowTimestamp = null): string
    {
        if ($isoTimestamp === null || trim($isoTimestamp) === '') {
            return 'Never synced';
        }

        $time = strtotime($isoTimestamp);
        if ($time === false || $time <= 0) {
            return 'Never synced';
        }

        $now = $nowTimestamp ?? time();
        $diff = $now - $time;

        if ($diff < 0) {
            return 'Just now';
        }
        if ($diff < 60) {
            return 'Synced just now';
        }
        if ($diff < 120) {
            return 'Synced 1 min ago';
        }
        if ($diff < 3600) {
            return sprintf('Synced %d mins ago', (int) floor($diff / 60));
        }
        if ($diff < 7200) {
            return 'Synced 1 hour ago';
        }
        if ($diff < 86400) {
            return sprintf('Synced %d hours ago', (int) floor($diff / 3600));
        }
        if ($diff < 172800) {
            return 'Synced 1 day ago';
        }

        return sprintf('Synced %d days ago', (int) floor($diff / 86400));
    }

    /**
     * Resolves aggregate health status across all tracked feeds.
     *
     * @param array<string|int, ChannelSyncStatus> $statuses
     * @return 'healthy'|'degraded'|'error'
     */
    public static function resolveAggregateHealth(array $statuses): string
    {
        if (empty($statuses)) {
            return ChannelSyncStatus::STATUS_HEALTHY;
        }

        $hasError = false;
        $hasDegraded = false;

        foreach ($statuses as $status) {
            if ($status->isError()) {
                $hasError = true;
            } elseif ($status->isDegraded()) {
                $hasDegraded = true;
            }
        }

        if ($hasError) {
            return ChannelSyncStatus::STATUS_ERROR;
        }
        if ($hasDegraded) {
            return ChannelSyncStatus::STATUS_DEGRADED;
        }

        return ChannelSyncStatus::STATUS_HEALTHY;
    }

    /**
     * Resolves the most recent lastSyncedAt ISO string across all tracked feeds.
     *
     * @param array<string|int, ChannelSyncStatus> $statuses
     */
    public static function resolveLatestSyncTimestamp(array $statuses): ?string
    {
        $latestTime = null;
        $latestIso = null;

        foreach ($statuses as $status) {
            $syncedAt = $status->getLastSyncedAt();
            if ($syncedAt !== null) {
                $ts = strtotime($syncedAt);
                if ($ts !== false && ($latestTime === null || $ts > $latestTime)) {
                    $latestTime = $ts;
                    $latestIso = $syncedAt;
                }
            }
        }

        return $latestIso;
    }

    /**
     * Resolves total active/cached blocked nights count across all tracked feeds.
     *
     * @param array<string|int, ChannelSyncStatus> $statuses
     */
    public static function resolveTotalBlockedNights(InboundChannelSyncServiceInterface $syncService, array $statuses): int
    {
        $total = 0;
        $propertiesToCheck = array_unique(array_merge(array_keys($statuses), ['1606', '1707']));

        foreach ($propertiesToCheck as $propId) {
            $propStr = (string) $propId;
            if (isset($statuses[$propStr])) {
                $cached = $syncService->getCachedNights($propStr);
                $total += $cached !== null ? count($cached) : $statuses[$propStr]->getBlockedNightsCount();
            } else {
                $cached = $syncService->getCachedNights($propStr);
                if ($cached !== null) {
                    $total += count($cached);
                }
            }
        }

        return $total;
    }
}
