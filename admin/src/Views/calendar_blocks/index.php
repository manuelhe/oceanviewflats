<?php
/**
 * Master Calendar Blocks Management View.
 *
 * @var string $propertyId
 * @var string $filter
 * @var string $headerActionsHtml
 * @var string $viewContainerHtml
 * @var string $csrfToken
 * @var array<string, mixed>|null $channelSyncPanel
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
        <?= $headerActionsHtml ?>
    </div>

    <!-- Inbound Channel Sync Section (decoupled from filter swaps) -->
    <?php
    if (isset($channelSyncPanel)) {
        extract($channelSyncPanel, EXTR_OVERWRITE);
        include __DIR__ . '/_channel_sync_panel.php';
    }
    ?>

    <!-- Dynamic Calendar Blocks View Container (Filter Bar + Table) -->
    <?= $viewContainerHtml ?>

</div>

<!-- Modal Container for HTMX Modal Injection -->
<div id="modal-container"></div>

<!-- Reservation Detail Drawer Container -->
<div id="drawer-container"></div>
