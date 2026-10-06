<?php
/**
 * Header actions partial for Rates Management.
 *
 * @var string $propertyId
 * @var int $year
 * @var string $csrfToken
 * @var bool $oob
 */

$oob = $oob ?? false;
?>
<div id="rates-header-actions"<?= $oob ? ' hx-swap-oob="outerHTML"' : '' ?> class="flex items-center space-x-3">
    <button type="button"
            hx-post="/rates/seed-from-csv"
            hx-vals='{"property_id": "<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>", "year": <?= $year ?>, "csrf_token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"}'
            hx-target="#rates-view-container"
            hx-swap="outerHTML"
            hx-confirm="Seed default seasonal pricing from prices.csv? Non-overlapping tiers will be imported."
            class="inline-flex items-center px-3.5 py-2 border border-gray-300 text-xs font-semibold rounded-lg shadow-2xs text-gray-700 bg-white hover:bg-gray-50 transition cursor-pointer">
        <svg class="w-4 h-4 mr-1.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
        </svg>
        Seed from CSV
    </button>
    <button type="button"
            hx-get="/rates/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>"
            hx-target="#modal-container"
            hx-swap="innerHTML"
            class="inline-flex items-center px-3.5 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-2xs text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        + New Seasonal Tier
    </button>
</div>
