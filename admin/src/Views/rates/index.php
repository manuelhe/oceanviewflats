<?php
/**
 * Master Rates Management View.
 *
 * @var string $propertyId
 * @var int $year
 * @var string $headerActionsHtml
 * @var string $viewContainerHtml
 * @var string|null $modalHtml
 * @var string $csrfToken
 */
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
        <?= $headerActionsHtml ?>
    </div>

    <!-- Dynamic Rates View Container (Filter Bar + Content) -->
    <?= $viewContainerHtml ?>

    <!-- Modal Injection Target Container -->
    <div id="modal-container"><?= $modalHtml ?? '' ?></div>

</div>
