<?php
/**
 * Master Calendar Blocks Management View.
 *
 * @var string $propertyId
 * @var string $filter
 * @var list<array<string, mixed>> $blocks
 * @var string $csrfToken
 */
?>

<div class="space-y-6">

    <!-- Page Header & Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Maintenance & Calendar Holds</h1>
            <p class="text-xs text-gray-500 mt-1">
                Block calendar dates for unit maintenance, deep cleaning, and host reservations. Automatically syncs to outbound iCal feeds.
            </p>
        </div>
        <div>
            <button type="button"
                    hx-get="/calendar-blocks/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
                    hx-target="#modal-container"
                    class="inline-flex items-center px-4 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-2xs text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + Add Maintenance Hold
            </button>
        </div>
    </div>

    <!-- Filter Bar (Unit Filter & Timeframe Selector) -->
    <div class="bg-white p-4 rounded-xl border border-gray-200 shadow-2xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        
        <!-- Unit Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Unit:</span>
            <a href="/calendar-blocks?property_id=all&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=all&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === 'all' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                All Properties
            </a>
            <a href="/calendar-blocks?property_id=1606&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=1606&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1606' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1606
            </a>
            <a href="/calendar-blocks?property_id=1707&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-get="/calendar-blocks?property_id=1707&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $propertyId === '1707' ? 'bg-indigo-600 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                Apartment 1707
            </a>
        </div>

        <!-- Timeframe Selector -->
        <div class="flex items-center space-x-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider mr-1">Status:</span>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=upcoming"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=upcoming"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'upcoming' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                Active & Upcoming
            </a>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=past"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=past"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'past' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                Concluded (Past)
            </a>
            <a href="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=all"
               hx-get="/calendar-blocks?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=all"
               hx-target="#blocks-container"
               hx-push-url="true"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer <?= $filter === 'all' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                All History
            </a>
        </div>
    </div>

    <!-- Blocks Table Dynamic Target -->
    <div id="blocks-container">
        <?php require __DIR__ . '/_table.php'; ?>
    </div>

</div>

<!-- Modal Container for HTMX Modal Injection -->
<div id="modal-container"></div>
