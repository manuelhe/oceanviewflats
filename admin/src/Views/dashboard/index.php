<?php
/**
 * @var array<string, mixed> $currentUser
 */
?>
<div class="space-y-6">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-100 p-6">
        <div class="md:flex md:items-center md:justify-between">
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl font-bold leading-7 text-gray-900 sm:text-3xl sm:truncate">
                    Welcome back, <?= htmlspecialchars((string) ($currentUser['name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    Role: <span class="font-medium text-gray-700 uppercase"><?= htmlspecialchars((string) ($currentUser['role'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?></span> &bull; 
                    Active Session: Subdomain Isolated &bull; Strict CSRF Protection Enabled
                </p>
            </div>
            <div class="mt-4 flex md:mt-0 md:ml-4 space-x-3">
                <a href="/bookings/manual" class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    + Manual Booking
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Stat Cards -->
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
        <div class="bg-white overflow-hidden shadow-sm rounded-xl border border-gray-100 p-5">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-indigo-50 rounded-lg p-3 text-indigo-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Reservations & Calendar</dt>
                        <dd class="mt-1">
                            <a href="/reservations" class="text-indigo-600 hover:text-indigo-900 font-semibold text-sm">
                                View Unified Grid &rarr;
                            </a>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm rounded-xl border border-gray-100 p-5">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-emerald-50 rounded-lg p-3 text-emerald-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Channel Feeds</dt>
                        <dd class="mt-1 text-sm font-semibold text-emerald-600">
                            Airbnb iCal Feeds Active
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="bg-white overflow-hidden shadow-sm rounded-xl border border-gray-100 p-5">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-amber-50 rounded-lg p-3 text-amber-600">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                </div>
                <div class="ml-5 w-0 flex-1">
                    <dl>
                        <dt class="text-sm font-medium text-gray-500 truncate">Smart Lock Status</dt>
                        <dd class="mt-1 text-sm font-semibold text-gray-700">
                            Keypad PIN Integrations Ready
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
