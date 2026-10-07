<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Service;

use DateTimeImmutable;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use OceanViewFlats\Admin\Repository\AdminRateRepository;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearanceRepositoryInterface;
use OceanViewFlats\Domain\Fulfillment\PdoCondominiumClearanceRepository;
use OceanViewFlats\Domain\Reservation\ChannelBlock;
use OceanViewFlats\Domain\Reservation\Dashboard\AlertSeverity;
use OceanViewFlats\Domain\Reservation\Dashboard\DashboardHubViewData;
use OceanViewFlats\Domain\Reservation\Dashboard\DashboardQueryServiceInterface;
use OceanViewFlats\Domain\Reservation\Dashboard\MovementType;
use OceanViewFlats\Domain\Reservation\Dashboard\OperationalAlert;
use OceanViewFlats\Domain\Reservation\Dashboard\OperationsEvent;
use OceanViewFlats\Domain\Reservation\Dashboard\PropertyRateStatus;
use OceanViewFlats\Domain\Reservation\InboundChannelSyncServiceInterface;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;

final class DashboardQueryService implements DashboardQueryServiceInterface
{
    private readonly CondominiumClearanceRepositoryInterface $clearanceRepo;

    public function __construct(
        private readonly AdminReservationRepository $reservationRepo,
        private readonly AdminCalendarBlockRepository $calendarBlockRepo,
        private readonly AdminRateRepository $rateRepo,
        private readonly InboundChannelSyncServiceInterface $channelSyncService,
        private readonly ?ReservationLedgerInterface $ledger = null,
        ?CondominiumClearanceRepositoryInterface $clearanceRepo = null
    ) {
        $this->clearanceRepo = $clearanceRepo ?? new PdoCondominiumClearanceRepository($this->reservationRepo->getPdo());
    }

    public function getDashboardHubData(
        string $propertyId = 'all',
        ?DateTimeImmutable $now = null
    ): DashboardHubViewData {
        $now = $now ?? new DateTimeImmutable('today');
        $today = $now->format('Y-m-d');
        $horizonEnd = $now->modify('+6 days')->format('Y-m-d');

        // 1. 7-Day Operational Schedule with turnover detection
        $events = $this->reservationRepo->getOperationalSchedule($propertyId, $today, $horizonEnd);

        // Group into all 7 days so view template receives an array keyed by each date
        /** @var array<string, list<OperationsEvent>> $scheduleByDate */
        $scheduleByDate = [];
        for ($i = 0; $i < 7; $i++) {
            $dateKey = $now->modify("+{$i} days")->format('Y-m-d');
            $scheduleByDate[$dateKey] = [];
        }
        foreach ($events as $event) {
            if (isset($scheduleByDate[$event->date])) {
                $scheduleByDate[$event->date][] = $event;
            }
        }

        // 2. Today's movements counters
        $todayArrivalsCount = 0;
        $todayDeparturesCount = 0;
        if (isset($scheduleByDate[$today])) {
            foreach ($scheduleByDate[$today] as $event) {
                if ($event->movementType === MovementType::CHECK_IN || $event->movementType === MovementType::TURNOVER) {
                    $todayArrivalsCount++;
                } elseif ($event->movementType === MovementType::CHECK_OUT) {
                    $todayDeparturesCount++;
                }
            }
        }

        // Active stays count
        $activeStaysCount = $this->reservationRepo->getActiveStaysCount($propertyId, $now);

        // 3. Operational Alerts
        // Incomplete guest registries (up to 3 days lookahead + in-house)
        $registryAlerts = $this->reservationRepo->getIncompleteRegistryAlerts($propertyId, 3, $now);

        // Failed condominium clearances
        $clearanceAlerts = $this->clearanceRepo->getFailedClearanceAlerts($propertyId, $now);

        // Un-onboarded channel blocks
        /** @var list<ChannelBlock> $channelBlocks */
        $channelBlocks = [];
        if ($this->ledger !== null) {
            $propIds = $propertyId === 'all' ? ['1606', '1707'] : [$propertyId];
            foreach ($propIds as $pid) {
                $channelBlocks = array_merge($channelBlocks, $this->ledger->getChannelBlocks($pid));
            }
        }
        $channelAlerts = $this->reservationRepo->getUnonboardedChannelBlockAlerts($channelBlocks, $propertyId, $now);

        $alerts = array_merge($registryAlerts, $clearanceAlerts, $channelAlerts);
        // Sort alerts by severity: CRITICAL, then WARNING, then INFO; then dueDate ASC
        usort($alerts, static function (OperationalAlert $a, OperationalAlert $b): int {
            $severityRank = [
                AlertSeverity::CRITICAL->value => 0,
                AlertSeverity::WARNING->value => 1,
                AlertSeverity::INFO->value => 2,
            ];
            $rankA = $severityRank[$a->severity->value];
            $rankB = $severityRank[$b->severity->value];
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp($a->dueDate, $b->dueDate);
        });

        // 4. Rate Statuses
        $targetProps = $propertyId === 'all' ? ['1606', '1707'] : [$propertyId];
        /** @var array<string|int, PropertyRateStatus> $rateStatus */
        $rateStatus = [];
        foreach ($targetProps as $pid) {
            $propKey = (string) $pid;
            $rateStatus[$propKey] = $this->rateRepo->getPropertyRateStatus($propKey, $now);
        }

        // 5. Upcoming Maintenance Blocks (14-day horizon)
        $upcomingMaintenanceBlocks = $this->calendarBlockRepo->getUpcomingBlocks($propertyId, 14, $now);

        // 6. Channel Sync Status
        $statuses = $this->channelSyncService->getAllStatuses();
        $isHealthy = !empty($statuses);
        foreach ($statuses as $status) {
            if (!$status->isHealthy()) {
                $isHealthy = false;
                break;
            }
        }
        $channelSyncData = [
            'statuses' => $statuses,
            'is_healthy' => $isHealthy,
        ];

        return new DashboardHubViewData(
            selectedPropertyFilter: $propertyId,
            alerts: $alerts,
            scheduleByDate: $scheduleByDate,
            rateStatus: $rateStatus,
            upcomingMaintenanceBlocks: $upcomingMaintenanceBlocks,
            channelSyncData: $channelSyncData,
            todayArrivalsCount: $todayArrivalsCount,
            todayDeparturesCount: $todayDeparturesCount,
            activeStaysCount: $activeStaysCount
        );
    }
}
