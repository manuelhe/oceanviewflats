<?php
/**
 * HTMX rates table partial.
 *
 * @var string $propertyId
 * @var int $year
 * @var list<array<string, mixed>> $tiers
 * @var string $csrfToken
 */
?>

<div class="bg-white rounded-xl border border-gray-200 shadow-2xs overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h3 class="text-sm font-bold text-gray-900">
                Configured Seasonal Tiers (<?= count($tiers) ?>)
            </h3>
            <p class="text-xs text-gray-500 mt-0.5">
                Active pricing intervals taking precedence over property <?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?> base rates.
            </p>
        </div>
        <div class="flex items-center space-x-2">
            <button type="button"
                    hx-get="/rates/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>"
                    hx-target="#modal-container"
                    hx-swap="innerHTML"
                    class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-semibold rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 shadow-2xs transition cursor-pointer">
                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                + Add Seasonal Tier
            </button>
        </div>
    </div>

    <?php if (empty($tiers)): ?>
        <div class="p-12 text-center space-y-3">
            <div class="w-12 h-12 mx-auto rounded-full bg-indigo-50 text-indigo-500 flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h4 class="text-sm font-semibold text-gray-900">No seasonal rate tiers found</h4>
            <p class="text-xs text-gray-500 max-w-sm mx-auto">
                All bookings for Property <?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?> in <?= $year ?> are currently priced using the base rate. You can create custom seasonal tiers or import default rates.
            </p>
            <div class="pt-2 flex items-center justify-center space-x-3">
                <button type="button"
                        hx-get="/rates/new?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>"
                        hx-target="#modal-container"
                        hx-swap="innerHTML"
                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                    + Add New Tier
                </button>
                <button type="button"
                        hx-post="/rates/seed-from-csv"
                        hx-vals='{"property_id": "<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>", "year": <?= $year ?>, "csrf_token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"}'
                        hx-target="#rates-view-container"
                        hx-swap="outerHTML"
                        hx-confirm="Seed default seasonal pricing from prices.csv? Non-overlapping tiers will be imported."
                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-lg text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition cursor-pointer">
                    Seed from prices.csv
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                <thead class="bg-gray-50 text-gray-500 font-semibold uppercase tracking-wider text-[11px]">
                    <tr>
                        <th scope="col" class="px-5 py-3">Season Name</th>
                        <th scope="col" class="px-5 py-3">Dates (Interval)</th>
                        <th scope="col" class="px-5 py-3">Duration</th>
                        <th scope="col" class="px-5 py-3">Nightly Rate</th>
                        <th scope="col" class="px-5 py-3">Min Stay</th>
                        <th scope="col" class="px-5 py-3">Created By</th>
                        <th scope="col" class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white text-gray-700">
                    <?php foreach ($tiers as $tier): ?>
                        <?php
                        $start = new DateTimeImmutable((string) $tier['start_date']);
                        $end = new DateTimeImmutable((string) $tier['end_date']);
                        $nights = (int) $start->diff($end->modify('+1 day'))->days;
                        $rateId = (int) $tier['id'];
                        ?>
                        <tr class="hover:bg-gray-50/70 transition">
                            <td class="px-5 py-3.5 font-semibold text-gray-900">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                                    <?= htmlspecialchars((string) $tier['season_name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-gray-600">
                                <?= htmlspecialchars((string) $tier['start_date'], ENT_QUOTES, 'UTF-8') ?> &rarr; <?= htmlspecialchars((string) $tier['end_date'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="px-5 py-3.5 text-gray-500">
                                <?= $nights ?> <?= $nights === 1 ? 'night' : 'nights' ?>
                            </td>
                            <td class="px-5 py-3.5 font-semibold text-gray-900">
                                $<?= number_format((float) $tier['price_per_night'], 0, ',', '.') ?> <span class="text-[10px] text-gray-500 font-normal">COP</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-indigo-50 text-indigo-700">
                                    <?= (int) $tier['min_stay'] ?> <?= (int) $tier['min_stay'] === 1 ? 'nt' : 'nts' ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-gray-500 text-[11px]">
                                <div><?= htmlspecialchars((string) ($tier['created_by_name'] ?? 'System'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-[10px] text-gray-400"><?= htmlspecialchars(substr((string) ($tier['created_at'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-2">
                                <button type="button"
                                        hx-get="/rates/<?= $rateId ?>/edit"
                                        hx-target="#modal-container"
                                        hx-swap="innerHTML"
                                        class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 rounded transition cursor-pointer">
                                    Edit
                                </button>
                                <button type="button"
                                        hx-delete="/rates/<?= $rateId ?>?property_id=<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>&year=<?= $year ?>"
                                        hx-headers='{"X-CSRF-Token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"}'
                                        hx-target="#rates-view-container"
                                        hx-swap="outerHTML"
                                        hx-confirm="Are you sure you want to delete seasonal rate tier '<?= htmlspecialchars((string) $tier['season_name'], ENT_QUOTES, 'UTF-8') ?>'? Dates will revert to property base pricing."
                                        class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-rose-600 hover:text-rose-900 hover:bg-rose-50 rounded transition cursor-pointer">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
