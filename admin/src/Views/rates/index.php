<?php
/**
 * Master Rates Management View.
 *
 * @var string $propertyId
 * @var int $year
 * @var string $contentHtml
 * @var string|null $modalHtml
 * @var string $csrfToken
 */

$years = [$year - 1, $year, $year + 1];
?>

<div class="space-y-6">

    <!-- Page Header & Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Seasonal Pricing & Rates</h1>
            <p class="text-xs text-gray-500 mt-1">
                Configure seasonal rate tiers, minimum stay policies, and inspect timeline coverage across all properties.
            </p>
        </div>
        <div class="flex items-center space-x-3">
            <button type="button"
                    hx-post="/rates/seed-from-csv"
                    hx-vals='{"property_id": "<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>", "year": <?= $year ?>, "csrf_token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"}'
                    hx-target="#rates-content"
                    hx-confirm="Seed default seasonal pricing from prices.csv? Non-overlapping tiers will be imported."
                    class="inline-flex items-center px-3.5 py-2 border border-gray-300 text-xs font-semibold rounded-lg shadow-2xs text-gray-700 bg-white hover:bg-gray-50 transition cursor-pointer">
                <svg class="w-4 h-4 mr-1.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                </svg>
                Seed from CSV
            </button>
            <button type="button"
                    hx-get="/rates/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>"
                    hx-target="#modal-container"
                    class="inline-flex items-center px-3.5 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-2xs text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + New Seasonal Tier
            </button>
        </div>
    </div>

    <!-- Navigation & Filter Bar (Property Tabs & Year Selector) -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        
        <!-- Property Tabs -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Unit:</span>
            <a href="/rates?property_id=1606&year=<?= $year ?>"
               hx-get="/rates?property_id=1606&year=<?= $year ?>"
               hx-target="#rates-content"
               hx-push-url="true"
               class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1606' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1606
            </a>
            <a href="/rates?property_id=1707&year=<?= $year ?>"
               hx-get="/rates?property_id=1707&year=<?= $year ?>"
               hx-target="#rates-content"
               hx-push-url="true"
               class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1707' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1707
            </a>
        </div>

        <!-- Year Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Year:</span>
            <?php foreach ($years as $y): ?>
                <a href="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $y ?>"
                   hx-get="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $y ?>"
                   hx-target="#rates-content"
                   hx-push-url="true"
                   class="px-3 py-1 rounded-lg text-xs font-medium transition cursor-pointer <?= $y === $year ? 'bg-gray-900 text-white font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                    <?= $y ?>
                </a>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Dynamic HTMX Container -->
    <div id="rates-content"
         hx-get="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>"
         hx-trigger="rateUpdated from:body">
        <?= $contentHtml ?>
    </div>

    <!-- Modal Injection Target Container -->
    <div id="modal-container"><?= $modalHtml ?? '' ?></div>

</div>
