<?php
/**
 * HTMX Partial: Dashboard Rates & Calendar Holds Card
 *
 * @var \OceanViewFlats\Domain\Reservation\Dashboard\DashboardHubViewData $viewData
 * @var string $propertyId
 */
$rateStatuses = $viewData->rateStatus;
$blocks = $viewData->upcomingMaintenanceBlocks;

$propertiesToShow = ($propertyId === 'all') ? ['1606', '1707'] : [$propertyId];
?>
<div class="space-y-6">
    <!-- Property Rates Status Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-2xs overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-200 flex items-center justify-between bg-gray-50/70">
            <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Today's Effective Rates</h3>
            <a href="/rates" class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold">Manage Rates &rarr;</a>
        </div>
        <div class="p-4 space-y-3">
            <?php foreach ($propertiesToShow as $prop): ?>
                <?php
                /** @var \OceanViewFlats\Domain\Reservation\Dashboard\PropertyRateStatus|null $status */
                $status = $rateStatuses[$prop] ?? null;
                if ($status === null) {
                    continue;
                }
                ?>
                <div class="p-3 rounded-lg border border-gray-200 bg-gray-50/50 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-gray-900">Apartment <?= htmlspecialchars($prop, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $status->isSeasonalTierActive ? 'bg-indigo-100 text-indigo-800 border border-indigo-200' : 'bg-gray-100 text-gray-700' ?>">
                            <?= $status->isSeasonalTierActive ? 'Seasonal: ' . htmlspecialchars((string) $status->activeTierName, ENT_QUOTES, 'UTF-8') : 'Baseline Standard' ?>
                        </span>
                    </div>

                    <div class="flex items-baseline space-x-2">
                        <span class="text-base font-extrabold text-gray-900">
                            $<?= number_format($status->currentNightlyRate, 0, ',', '.') ?> COP
                        </span>
                        <span class="text-[11px] text-gray-500">/ night</span>
                    </div>

                    <?php if ($status->isSeasonalTierActive && !empty($status->activeTierEndDate)): ?>
                        <div class="text-[11px] text-indigo-700 font-medium">
                            Active tier ends <?= htmlspecialchars($status->activeTierEndDate, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($status->nextTierName) && $status->nextTierRate !== null): ?>
                        <div class="text-[11px] text-gray-600 border-t border-gray-200 pt-1.5 mt-1.5 flex items-center justify-between">
                            <span>Upcoming: <strong><?= htmlspecialchars($status->nextTierName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span class="font-semibold text-gray-800">$<?= number_format($status->nextTierRate, 0, ',', '.') ?> COP (<?= htmlspecialchars((string) $status->nextTierStartDate, ENT_QUOTES, 'UTF-8') ?>)</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Maintenance Holds Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-2xs overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-200 flex items-center justify-between bg-gray-50/70">
            <h3 class="text-xs font-bold text-gray-900 uppercase tracking-wider">Upcoming Calendar Holds</h3>
            <a href="/calendar-blocks/new"
               hx-get="/calendar-blocks/new"
               hx-target="#modal-container"
               hx-swap="innerHTML"
               role="button"
               class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold cursor-pointer">
                + Add Hold
            </a>
        </div>
        <div class="p-4 space-y-2.5">
            <?php if (empty($blocks)): ?>
                <div class="p-4 text-center text-xs text-gray-400">
                    No active maintenance holds in next 14 days.
                </div>
            <?php else: ?>
                <?php foreach ($blocks as $block): ?>
                    <div class="p-2.5 rounded-lg border border-gray-200 bg-white hover:border-gray-300 transition shadow-2xs text-xs space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold text-gray-900 truncate"><?= htmlspecialchars($block->reason, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold shrink-0 <?= $block->propertyId === '1606' ? 'bg-sky-100 text-sky-800' : 'bg-purple-100 text-purple-800' ?>">
                                Apt <?= htmlspecialchars($block->propertyId, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="text-[11px] text-gray-500 flex items-center justify-between">
                            <span><?= htmlspecialchars($block->startDate, ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars($block->endDate, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($block->createdByName)): ?>
                                <span class="italic text-gray-400"><?= htmlspecialchars($block->createdByName, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="pt-2 text-right">
                    <a href="/calendar-blocks" class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold">
                        View all calendar blocks &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
