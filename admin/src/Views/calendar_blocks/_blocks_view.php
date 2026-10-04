<?php
/**
 * Dynamic Calendar Blocks View Container Partial (Filter Bar + Table).
 *
 * @var string $propertyId
 * @var string $filter
 * @var string $tableHtml
 * @var bool $oob
 */

$oob = $oob ?? false;
?>
<div id="blocks-view-container"<?= $oob ? ' hx-swap-oob="outerHTML"' : '' ?> class="space-y-6">

    <!-- Filter Bar (Property Filter & Timeframe Selector) -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        
        <!-- Property Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Property:</span>
            <a href="/calendar-blocks?property_id=all&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=all&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $propertyId === 'all' ? 'aria-current="page" ' : '' ?>class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === 'all' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                All Properties
            </a>
            <a href="/calendar-blocks?property_id=1606&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=1606&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $propertyId === '1606' ? 'aria-current="page" ' : '' ?>class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1606' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1606
            </a>
            <a href="/calendar-blocks?property_id=1707&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=1707&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $propertyId === '1707' ? 'aria-current="page" ' : '' ?>class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1707' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1707
            </a>
        </div>

        <!-- Timeframe Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Status:</span>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=upcoming"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=upcoming"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $filter === 'upcoming' ? 'aria-current="page" ' : '' ?>class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'upcoming' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                Active & Upcoming
            </a>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=past"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=past"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $filter === 'past' ? 'aria-current="page" ' : '' ?>class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'past' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                Concluded (Past)
            </a>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=all"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=all"
               hx-target="#blocks-view-container"
               hx-swap="outerHTML"
               hx-push-url="true"
               <?= $filter === 'all' ? 'aria-current="page" ' : '' ?>class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'all' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                All History
            </a>
        </div>
    </div>

    <!-- Blocks Table Dynamic Target -->
    <div id="blocks-container">
        <?= $tableHtml ?>
    </div>

</div>
