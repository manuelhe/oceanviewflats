<?php
/**
 * Maintenance Calendar Blocks Table Partial.
 *
 * @var list<array<string, mixed>> $blocks
 * @var string $propertyId
 * @var string $filter
 * @var string $csrfToken
 */

$today = date('Y-m-d');
?>

<div class="bg-white rounded-xl border border-gray-200 shadow-2xs overflow-hidden">
    <?php if (empty($blocks)): ?>
        <div class="p-12 text-center">
            <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-500 mx-auto flex items-center justify-center mb-3">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">No maintenance holds found</h3>
            <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">
                There are no maintenance blocks matching the selected filter criteria. Create a hold to block dates for repairs or host use.
            </p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
                <thead class="bg-gray-50/75 text-gray-500 font-semibold uppercase tracking-wider">
                    <tr>
                        <th scope="col" class="px-6 py-3.5">Property</th>
                        <th scope="col" class="px-6 py-3.5">Dates & Nights</th>
                        <th scope="col" class="px-6 py-3.5">Reason</th>
                        <th scope="col" class="px-6 py-3.5">Created By</th>
                        <th scope="col" class="px-6 py-3.5">Status</th>
                        <th scope="col" class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <?php foreach ($blocks as $block):
                        $bId = (int) $block['id'];
                        $bProp = (string) $block['property_id'];
                        $bStart = (string) $block['start_date'];
                        $bEnd = (string) $block['end_date'];
                        $bReason = (string) $block['reason'];
                        $bCreator = (string) ($block['created_by_name'] ?? 'Admin');
                        $createdTs = isset($block['created_at']) ? strtotime((string) $block['created_at']) : false;
                        $bCreatedAt = $createdTs !== false ? date('M j, Y', $createdTs) : '';

                        $startTs = strtotime($bStart);
                        $endTs = strtotime($bEnd);
                        if ($startTs === false || $endTs === false) {
                            continue;
                        }
                        $nights = (int) round(($endTs - $startTs) / 86400);

                        $isConcluded = $bEnd <= $today;
                        $isActive = ($bStart <= $today) && ($bEnd > $today);
                        $isUpcoming = $bStart > $today;
                    ?>
                    <tr class="hover:bg-gray-50/80 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold <?= $bProp === '1606' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
                                Apt <?= htmlspecialchars($bProp, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-medium text-gray-900">
                                <?= date('M j, Y', $startTs) ?> &rarr; <?= date('M j, Y', $endTs) ?>
                            </div>
                            <div class="text-gray-500 text-[11px] mt-0.5">
                                <?= $nights ?> <?= $nights === 1 ? 'night' : 'nights' ?>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-gray-900 max-w-xs truncate" title="<?= htmlspecialchars($bReason, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($bReason, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php if ($bCreatedAt !== ''): ?>
                                <div class="text-[11px] text-gray-400">Added <?= $bCreatedAt ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                            <?= htmlspecialchars($bCreator, ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($isActive): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                    <span class="w-1.5 h-1.5 mr-1.5 bg-amber-500 rounded-full animate-pulse"></span>
                                    Active Hold
                                </span>
                            <?php elseif ($isUpcoming): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    Upcoming
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                    Concluded
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium">
                            <?php if ($isConcluded): ?>
                                <span class="inline-flex items-center text-gray-400 cursor-not-allowed text-[11px]" title="Concluded historical maintenance blocks cannot be deleted (ADR 0006).">
                                    <svg class="w-3.5 h-3.5 mr-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    Locked
                                </span>
                            <?php else: ?>
                                <button type="button"
                                        hx-delete="/calendar-blocks/<?= $bId ?>"
                                        hx-vals='{"csrf_token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>", "property_id": "<?= htmlspecialchars($propertyId, ENT_QUOTES, 'UTF-8') ?>", "filter": "<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>"}'
                                        hx-target="#blocks-container"
                                        hx-confirm="Are you sure you want to release this maintenance hold? The dates will immediately become available for direct bookings and channel sync."
                                        class="text-red-600 hover:text-red-900 font-medium inline-flex items-center transition cursor-pointer">
                                    <svg class="w-4 h-4 mr-1 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Release
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
