<?php
/**
 * Dynamic Rates View Container (Filter Bar + Content).
 *
 * @var string $propertyId
 * @var int $year
 * @var string $contentHtml
 * @var string $csrfToken
 * @var bool $oob
 */

$oob = $oob ?? false;
$years = [$year - 1, $year, $year + 1];
?>
<div id="rates-view-container"<?= $oob ? ' hx-swap-oob="outerHTML"' : '' ?>
     class="space-y-6"
     hx-get="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>"
     hx-trigger="rateUpdated from:body"
     hx-swap="outerHTML">

    <!-- Navigation & Filter Bar (Property Tabs & Year Selector) -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        
        <!-- Property Tabs -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Property:</span>
            <a href="/rates?property_id=1606&year=<?= $year ?>"
               hx-get="/rates?property_id=1606&year=<?= $year ?>"
               hx-target="#rates-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $propertyId === '1606' ? 'aria-current="page" ' : '' ?>class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1606' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1606
            </a>
            <a href="/rates?property_id=1707&year=<?= $year ?>"
               hx-get="/rates?property_id=1707&year=<?= $year ?>"
               hx-target="#rates-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $propertyId === '1707' ? 'aria-current="page" ' : '' ?>class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1707' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1707
            </a>
        </div>

        <!-- Year Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Year:</span>
            <?php foreach ($years as $y): ?>
                <a href="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $y ?>"
                   hx-get="/rates?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $y ?>"
                   hx-target="#rates-view-container"
                   hx-swap="outerHTML"
                   hx-push-url="true"
                   <?= $y === $year ? 'aria-current="page" ' : '' ?>class="px-3 py-1 rounded-lg text-xs font-medium transition cursor-pointer <?= $y === $year ? 'bg-gray-900 text-white font-semibold' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                    <?= $y ?>
                </a>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- Dynamic HTMX Content Partial -->
    <div id="rates-content">
        <?= $contentHtml ?>
    </div>

</div>
