<?php
/**
 * Header actions partial for Calendar Blocks Management.
 *
 * @var string $propertyId
 * @var string $filter
 * @var bool $oob
 */

$oob = $oob ?? false;
?>
<div id="calendar-blocks-header-actions"<?= $oob ? ' hx-swap-oob="outerHTML"' : '' ?>>
    <button type="button"
            hx-get="/calendar-blocks/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&filter=<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"
            hx-target="#modal-container"
            hx-swap="innerHTML"
            class="inline-flex items-center px-4 py-2 border border-transparent text-xs font-semibold rounded-lg shadow-2xs text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        + Add Maintenance Hold
    </button>
</div>
