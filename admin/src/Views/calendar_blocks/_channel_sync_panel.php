<?php
/**
 * Inbound Channel Sync Panel Partial for Calendar Blocks.
 *
 * Rendered as an HTMX partial or included in the master calendar blocks view.
 * Supports on-demand manual sync for all feeds or individual units,
 * live health indicators, masked feed links, and diagnostic error banners per ADR 0002.
 *
 * @var string $health ('healthy'|'degraded'|'error')
 * @var string $badgeText
 * @var string $badgeClasses
 * @var string $badgeDotClass
 * @var string|null $lastSyncedAt
 * @var string $relativeSyncedTime
 * @var int $totalBlockedNights
 * @var array<string, array{
 *     id: string,
 *     name: string,
 *     status: ?\OceanViewFlats\Domain\Reservation\ChannelSyncStatus,
 *     health: 'healthy'|'degraded'|'error',
 *     badgeText: string,
 *     badgeClasses: string,
 *     badgeDotClass: string,
 *     lastSyncedAt: ?string,
 *     lastAttemptedAt: ?string,
 *     relativeSyncedTime: string,
 *     blockedNightsCount: int,
 *     feedUrl: ?string,
 *     maskedFeedUrl: string,
 *     httpCode: ?int,
 *     errorMessage: ?string,
 *     isDegraded: bool,
 *     isError: bool
 * }> $properties
 * @var bool $hasDiagnostics
 * @var list<array{
 *     propertyId: string,
 *     propertyName: string,
 *     isDegraded: bool,
 *     isError: bool,
 *     httpCode: ?int,
 *     errorMessage: string,
 *     blockedNightsRetained: int
 * }> $diagnostics
 * @var array{type: string, message: string}|null $syncNotice
 * @var string $noticeClasses
 * @var string $csrfToken
 */
?>
<div id="channel-sync-panel" class="bg-white rounded-xl border border-gray-200 shadow-2xs p-5 space-y-4">
    <!-- Panel Header: Title, Global Health & Global Sync Action -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-4">
        <div class="flex items-center space-x-3">
            <div class="flex-shrink-0 bg-indigo-50 rounded-lg p-2 text-indigo-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <h2 class="text-base font-bold text-gray-900 tracking-tight">Inbound Channel Sync</h2>
                    <span class="text-2xs font-semibold px-2 py-0.5 bg-gray-100 text-gray-600 rounded">Airbnb iCal</span>
                </div>
                <p class="text-xs text-gray-500 mt-0.5">
                    Live upstream calendar feeds imported to reserve dates, prevent double-bookings, and track external holds.
                </p>
            </div>
        </div>

        <div class="flex items-center space-x-3 flex-shrink-0">
            <!-- Global Health Status Badge -->
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border <?= $badgeClasses ?>">
                <span class="w-1.5 h-1.5 mr-1.5 rounded-full <?= $badgeDotClass ?>"></span>
                <?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?>
            </span>

            <!-- Global Sync All Button -->
            <button type="button"
                    hx-post="/channel-sync?view=panel"
                    hx-target="#channel-sync-panel"
                    hx-swap="outerHTML"
                    hx-indicator="#sync-spinner-all"
                    hx-disabled-elt="this"
                    class="inline-flex items-center px-3.5 py-1.5 border border-transparent text-xs font-semibold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition shadow-2xs cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                <span>Sync All</span>
                <span id="sync-spinner-all" class="htmx-indicator ml-1.5">
                    <svg class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                </span>
            </button>
        </div>
    </div>

    <!-- Diagnostic Error Banner (Upstream error callout banner if any property is Degraded or Error) -->
    <?php if (!empty($hasDiagnostics) && !empty($diagnostics)): ?>
        <div id="channel-sync-diagnostics" class="space-y-2">
            <?php foreach ($diagnostics as $diag): ?>
                <?php
                $isDegraded = !empty($diag['isDegraded']);
                $diagBg = $isDegraded ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-rose-50 border-rose-200 text-rose-900';
                $diagIconColor = $isDegraded ? 'text-amber-500' : 'text-rose-500';
                ?>
                <div class="p-3.5 rounded-lg border <?= $diagBg ?> text-xs flex items-start space-x-3">
                    <div class="flex-shrink-0 mt-0.5 <?= $diagIconColor ?>">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <div class="flex-1 space-y-1">
                        <div class="font-semibold flex items-center space-x-2">
                            <span>Upstream Feed Warning &bull; <?= htmlspecialchars($diag['propertyName'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($diag['httpCode'])): ?>
                                <span class="font-mono text-2xs px-1.5 py-0.2 bg-white bg-opacity-70 rounded border border-current">
                                    HTTP <?= (int) $diag['httpCode'] ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-opacity-95 leading-relaxed">
                            <span class="font-medium">Error:</span> <?= htmlspecialchars($diag['errorMessage'], ENT_QUOTES, 'UTF-8') ?>.
                            <?php if ($isDegraded): ?>
                                <span class="font-medium">Previous cached blocks (<?= (int) $diag['blockedNightsRetained'] ?> nights) were safely retained</span> to prevent unintended double-booking.
                            <?php else: ?>
                                No previous cached calendar blocks were available.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- User Action Notification Banner (Sync Feedback / Cooldown) -->
    <?php if (!empty($syncNotice)): ?>
        <div class="p-3 rounded-lg text-xs <?= $noticeClasses ?> flex items-center justify-between">
            <span><?= htmlspecialchars($syncNotice['message'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>

    <!-- Per-Unit Grid (Property 1606 and Property 1707) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($properties as $prop): ?>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 flex flex-col justify-between space-y-3">
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <span class="text-sm font-bold text-gray-900"><?= htmlspecialchars($prop['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-2xs font-semibold uppercase px-2 py-0.5 bg-gray-200 text-gray-700 rounded">Unit <?= htmlspecialchars($prop['id'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <!-- Individual Unit Health Badge -->
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-2xs font-medium border <?= $prop['badgeClasses'] ?>">
                            <span class="w-1.5 h-1.5 mr-1 rounded-full <?= $prop['badgeDotClass'] ?>"></span>
                            <?= htmlspecialchars($prop['badgeText'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <!-- Upstream Feed URL Indicator -->
                    <div class="text-xs text-gray-500 flex items-center space-x-1 min-w-0">
                        <span class="font-medium text-gray-600 flex-shrink-0">Feed URL:</span>
                        <span class="font-mono text-2xs text-gray-500 truncate" title="<?= htmlspecialchars($prop['feedUrl'] ?? 'Not configured', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($prop['maskedFeedUrl'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <!-- Blocked Nights Count & Last Sync Relative Time with ISO-8601 hover tooltip -->
                    <div class="pt-2 border-t border-gray-200/60 flex items-center justify-between text-xs text-gray-600">
                        <div>
                            <span class="text-base font-bold text-gray-900"><?= (int) $prop['blockedNightsCount'] ?></span>
                            <span class="text-gray-500 ml-1">blocked nights</span>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center cursor-help text-gray-500 hover:text-gray-700"
                                  title="<?= htmlspecialchars($prop['lastSyncedAt'] ?? 'Never synced', ENT_QUOTES, 'UTF-8') ?>">
                                <svg class="w-3.5 h-3.5 mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span><?= htmlspecialchars($prop['relativeSyncedTime'], ENT_QUOTES, 'UTF-8') ?></span>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Individual Unit Sync Action Footer -->
                <div class="pt-2 border-t border-gray-200/60 flex justify-end">
                    <button type="button"
                            hx-post="/channel-sync?property_id=<?= urlencode((string) $prop['id']) ?>&view=panel"
                            hx-target="#channel-sync-panel"
                            hx-swap="outerHTML"
                            hx-indicator="#sync-spinner-<?= htmlspecialchars((string) $prop['id'], ENT_QUOTES, 'UTF-8') ?>"
                            hx-disabled-elt="this"
                            class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-semibold rounded-lg text-gray-700 bg-white hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-indigo-500 shadow-2xs transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                        <span>Sync Unit</span>
                        <span id="sync-spinner-<?= htmlspecialchars((string) $prop['id'], ENT_QUOTES, 'UTF-8') ?>" class="htmx-indicator ml-1.5">
                            <svg class="animate-spin h-3.5 w-3.5 text-gray-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
