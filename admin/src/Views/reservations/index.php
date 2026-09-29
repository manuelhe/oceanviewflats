<?php
/**
 * @var string $tableHtml
 * @var string|null $drawerHtml
 * @var array<string, mixed> $filters
 * @var array<string, mixed> $currentUser
 */

$search = (string) ($filters['search'] ?? '');
$propertyId = (string) ($filters['property_id'] ?? 'all');
$status = (string) ($filters['status'] ?? 'all');
$registryStatus = (string) ($filters['registry_status'] ?? 'all');
$source = (string) ($filters['source'] ?? 'all');
$sortBy = (string) ($filters['sort_by'] ?? 'created_at');
$checkInFrom = (string) ($filters['check_in_from'] ?? '');
$checkInTo = (string) ($filters['check_in_to'] ?? '');
?>

<div class="space-y-6">

    <!-- Page Title & Header Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Reservations Management</h1>
            <p class="text-xs text-gray-500 mt-1">
                Monitor reservations, inspect guest registries, and verify access fulfillment across all properties.
            </p>
        </div>
        <div class="flex items-center space-x-3">
            <button type="button"
                    hx-get="/reservations/new"
                    hx-target="#modal-container"
                    class="inline-flex items-center px-3.5 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-2xs text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                + New Reservation
            </button>
            <span class="inline-flex items-center text-xs text-gray-500 bg-white border border-gray-200 px-3 py-1.5 rounded-lg shadow-2xs">
                <span class="w-2 h-2 rounded-full bg-emerald-500 mr-2"></span> Real-time Sync Active
            </span>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-2xs">
        <form id="filter-form"
              action="/reservations"
              method="GET"
              hx-get="/reservations"
              hx-target="#reservations-table-container"
              hx-push-url="true"
              hx-trigger="submit, keyup changed delay:300ms from:#search-input, change from:select, change from:input[type=date]"
              class="space-y-4">

            <!-- Primary Search Bar -->
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input type="search"
                       id="search-input"
                       name="search"
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Search by guest name, email, phone number, or reservation UID..."
                       class="block w-full pl-10 pr-4 py-2.5 text-sm bg-gray-50/50 border border-gray-300 rounded-lg placeholder-gray-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition">
            </div>

            <!-- Multi-field Filters Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                
                <!-- Property -->
                <div>
                    <label for="property-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Property</label>
                    <select id="property-filter" name="property_id" class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?= $propertyId === 'all' ? 'selected' : '' ?>>All Properties</option>
                        <option value="1606" <?= $propertyId === '1606' ? 'selected' : '' ?>>Apto 1606</option>
                        <option value="1707" <?= $propertyId === '1707' ? 'selected' : '' ?>>Apto 1707</option>
                    </select>
                </div>

                <!-- Status -->
                <div>
                    <label for="status-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Status</label>
                    <select id="status-filter" name="status" class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="pending_payment" <?= $status === 'pending_payment' ? 'selected' : '' ?>>Pending</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <!-- Registry -->
                <div>
                    <label for="registry-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Registry</label>
                    <select id="registry-filter" name="registry_status" class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?= $registryStatus === 'all' ? 'selected' : '' ?>>All Registries</option>
                        <option value="completed" <?= $registryStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="pending" <?= $registryStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>

                <!-- Source -->
                <div>
                    <label for="source-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Source</label>
                    <select id="source-filter" name="source" class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all" <?= $source === 'all' ? 'selected' : '' ?>>All Sources</option>
                        <option value="web" <?= $source === 'web' ? 'selected' : '' ?>>Web (Direct)</option>
                        <option value="cash" <?= $source === 'cash' ? 'selected' : '' ?>>Cash / Efecty</option>
                        <option value="bank_transfer" <?= $source === 'bank_transfer' ? 'selected' : '' ?>>Bank Wire (PSE)</option>
                        <option value="owner_stay" <?= $source === 'owner_stay' ? 'selected' : '' ?>>Owner Stay</option>
                        <option value="manual_override" <?= $source === 'manual_override' ? 'selected' : '' ?>>Manual Override</option>
                    </select>
                </div>

                <!-- Date Range From -->
                <div>
                    <label for="from-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Check-in From</label>
                    <input type="date"
                           id="from-filter"
                           name="check_in_from"
                           value="<?= htmlspecialchars($checkInFrom, ENT_QUOTES, 'UTF-8') ?>"
                           class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <!-- Date Range To -->
                <div>
                    <label for="to-filter" class="block text-[11px] font-bold text-gray-500 uppercase tracking-wider mb-1">Check-in To</label>
                    <input type="date"
                           id="to-filter"
                           name="check_in_to"
                           value="<?= htmlspecialchars($checkInTo, ENT_QUOTES, 'UTF-8') ?>"
                           class="block w-full px-2.5 py-1.5 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

            </div>

            <!-- Toolbar Footer (Sort & Reset) -->
            <div class="pt-2 flex flex-col sm:flex-row sm:items-center justify-between text-xs text-gray-500 border-t border-gray-100 gap-2">
                <div class="flex items-center space-x-2">
                    <span class="font-medium text-gray-700">Sort by:</span>
                    <select name="sort_by" class="px-2 py-1 text-xs bg-white border border-gray-300 rounded-md shadow-2xs focus:outline-none focus:ring-1 focus:ring-indigo-500">
                        <option value="created_at" <?= $sortBy === 'created_at' ? 'selected' : '' ?>>Newest Booking (Created At)</option>
                        <option value="check_in" <?= $sortBy === 'check_in' ? 'selected' : '' ?>>Stay Date (Check-in)</option>
                    </select>
                </div>
                <div>
                    <a href="/reservations" class="inline-flex items-center text-xs text-gray-500 hover:text-indigo-600 transition">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Reset All Filters
                    </a>
                </div>
            </div>

        </form>
    </div>

    <!-- Reservations Table Container -->
    <div id="reservations-table-container"
         hx-get="/reservations"
         hx-trigger="reservationUpdated from:body"
         hx-include="#filter-form">
        <?= $tableHtml ?>
    </div>

    <!-- Slide-over Detail Drawer Container (z-40) -->
    <div id="drawer-container">
        <?= $drawerHtml ?? '' ?>
    </div>

    <!-- Modal Container (z-50) -->
    <div id="modal-container"><?= $modalHtml ?? '' ?></div>

</div>
