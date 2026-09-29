<?php
/**
 * Visual seasonal timeline with unpriced gap warnings.
 *
 * @var string $propertyId
 * @var int $year
 * @var list<array<string, mixed>> $tiers
 * @var list<array{start_date: string, end_date: string, nights: int, fallback_rate: float}> $gaps
 * @var float $fallbackRate
 */

$isLeapYear = ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
$totalDaysInYear = $isLeapYear ? 366 : 365;

$gapNightsTotal = 0;
foreach ($gaps as $g) {
    $gapNightsTotal += $g['nights'];
}
$coveredDays = max(0, $totalDaysInYear - $gapNightsTotal);
$coveragePercent = (int) round(($coveredDays / $totalDaysInYear) * 100);

// Merge tiers and gaps into a unified chronological sequence
$timelineItems = [];
foreach ($tiers as $tier) {
    $timelineItems[] = [
        'type' => 'tier',
        'id' => (int) $tier['id'],
        'start_date' => (string) $tier['start_date'],
        'end_date' => (string) $tier['end_date'],
        'season_name' => (string) $tier['season_name'],
        'price_per_night' => (float) $tier['price_per_night'],
        'min_stay' => (int) $tier['min_stay'],
        'created_by_name' => $tier['created_by_name'] ?? null,
    ];
}

foreach ($gaps as $gap) {
    $timelineItems[] = [
        'type' => 'gap',
        'id' => null,
        'start_date' => $gap['start_date'],
        'end_date' => $gap['end_date'],
        'nights' => $gap['nights'],
        'fallback_rate' => $gap['fallback_rate'],
    ];
}

usort($timelineItems, fn($a, $b) => strcmp((string) $a['start_date'], (string) $b['start_date']));
?>

<div class="bg-white rounded-xl border border-gray-200 shadow-2xs p-5 space-y-5">
    
    <!-- Timeline Header & Annual Coverage Summary -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-100">
        <div>
            <div class="flex items-center space-x-2">
                <h2 class="text-base font-bold text-gray-900 tracking-tight">
                    Seasonal Timeline & Coverage (<?= $year ?>)
                </h2>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold <?= $coveragePercent === 100 ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>">
                    <?= $coveragePercent ?>% Covered
                </span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
                Property <?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?> &bull; Default Base Rate: <span class="font-semibold text-gray-700">$<?= number_format($fallbackRate, 0, ',', '.') ?> COP / night</span>
            </p>
        </div>

        <!-- Coverage Meter -->
        <div class="w-full sm:w-64 space-y-1.5">
            <div class="flex justify-between text-xs text-gray-500">
                <span>Priced: <strong class="text-gray-900"><?= $coveredDays ?>d</strong></span>
                <span>Unpriced Gaps: <strong class="<?= $gapNightsTotal > 0 ? 'text-amber-600 font-bold' : 'text-gray-600' ?>"><?= $gapNightsTotal ?>d</strong></span>
            </div>
            <div class="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden flex">
                <div class="h-full bg-indigo-600 transition-all duration-300" style="width: <?= $coveragePercent ?>%;"></div>
                <?php if ($gapNightsTotal > 0): ?>
                    <div class="h-full bg-amber-400 transition-all duration-300" style="width: <?= 100 - $coveragePercent ?>%;"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Unpriced Gaps Global Warning Alert -->
    <?php if (!empty($gaps)): ?>
        <div class="p-3.5 bg-amber-50/80 border border-amber-200 rounded-lg text-xs text-amber-900 flex items-start space-x-3">
            <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
            </svg>
            <div class="space-y-1">
                <p class="font-semibold text-amber-900">
                    Unpriced date windows detected in <?= $year ?>
                </p>
                <p class="text-amber-700 leading-relaxed">
                    Bookings created during unpriced windows fall back to the default property base rate ($<?= number_format($fallbackRate, 0, ',', '.') ?> COP / night). Create custom tiers below to assign high-season or holiday pricing.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Chronological Sequence List -->
    <div class="space-y-3">
        <?php if (empty($timelineItems)): ?>
            <div class="text-center py-8 text-gray-500 text-xs">
                No pricing intervals or gaps recorded for year <?= $year ?>.
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <?php foreach ($timelineItems as $item): ?>
                    <?php if ($item['type'] === 'tier'): ?>
                        <!-- Active Seasonal Rate Tier Card -->
                        <div class="bg-indigo-50/40 border border-indigo-200 rounded-xl p-4 flex flex-col justify-between hover:border-indigo-300 transition shadow-2xs">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-indigo-100 text-indigo-800">
                                        <?= htmlspecialchars((string) $item['season_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="text-[11px] text-gray-500 font-mono">
                                        Min <?= (int) $item['min_stay'] ?> <?= (int) $item['min_stay'] === 1 ? 'night' : 'nights' ?>
                                    </span>
                                </div>

                                <div class="text-sm font-bold text-gray-900">
                                    $<?= number_format((float) $item['price_per_night'], 0, ',', '.') ?> <span class="text-xs font-normal text-gray-500">COP / night</span>
                                </div>

                                <div class="text-xs text-gray-600 flex items-center space-x-1.5 font-mono">
                                    <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    <span><?= htmlspecialchars((string) $item['start_date'], ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars((string) $item['end_date'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>

                            <div class="pt-3 mt-3 border-t border-indigo-100/60 flex items-center justify-between text-xs">
                                <span class="text-[11px] text-gray-400">
                                    <?= !empty($item['created_by_name']) ? 'By ' . htmlspecialchars((string) $item['created_by_name'], ENT_QUOTES, 'UTF-8') : 'System Tier' ?>
                                </span>
                                <button type="button"
                                        hx-get="/rates/<?= (int) $item['id'] ?>/edit"
                                        hx-target="#modal-container"
                                        class="text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">
                                    Edit Tier
                                </button>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Unpriced Gap Warning Card -->
                        <div class="bg-amber-50/50 border border-dashed border-amber-300 rounded-xl p-4 flex flex-col justify-between hover:border-amber-400 transition shadow-2xs">
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800">
                                        <svg class="w-3 h-3 mr-1 text-amber-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                        Unpriced Gap (<?= (int) $item['nights'] ?>d)
                                    </span>
                                    <span class="text-[11px] text-amber-700 font-medium">Base Fallback</span>
                                </div>

                                <div class="text-sm font-bold text-gray-700">
                                    $<?= number_format((float) $item['fallback_rate'], 0, ',', '.') ?> <span class="text-xs font-normal text-gray-500">COP / night</span>
                                </div>

                                <div class="text-xs text-amber-800 flex items-center space-x-1.5 font-mono">
                                    <svg class="w-3.5 h-3.5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span><?= htmlspecialchars((string) $item['start_date'], ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars((string) $item['end_date'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>

                            <div class="pt-3 mt-3 border-t border-amber-200/60 flex items-center justify-between text-xs">
                                <span class="text-[11px] text-amber-600 font-medium">No Custom Rate</span>
                                <button type="button"
                                        hx-get="/rates/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&start_date=<?= htmlspecialchars((string) $item['start_date'], ENT_QUOTES, 'UTF-8') ?>&end_date=<?= htmlspecialchars((string) $item['end_date'], ENT_QUOTES, 'UTF-8') ?>"
                                        hx-target="#modal-container"
                                        class="text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">
                                    + Add Tier
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>
