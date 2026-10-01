<?php
/**
 * @var array<string, mixed> $reservation
 * @var array<string, mixed>|null $registry
 * @var string $publicSiteUrl
 */

$uid = (string) ($reservation['reservation_uid'] ?? '');
$propertyId = (string) ($reservation['property_id'] ?? '');
$guestName = (string) ($reservation['guest_name'] ?? '');
$guestEmail = (string) ($reservation['guest_email'] ?? '');
$guestPhone = (string) ($reservation['guest_phone'] ?? '');

$registryCompleted = (int) ($reservation['registry_completed'] ?? 0) === 1 && $registry !== null;
$guestsPayload = $registry !== null && is_array($registry['guests_payload']) ? $registry['guests_payload'] : [];
$carPlates = (string) ($registry['car_plates'] ?? '');
$carModel = (string) ($registry['car_model'] ?? '');
$submittedAt = (string) ($registry['created_at'] ?? '');
$ipAddress = (string) ($registry['ip_address'] ?? '');

$checkIn = (string) ($reservation['check_in'] ?? '');
$checkOut = (string) ($reservation['check_out'] ?? '');
$lang = (string) ($reservation['lang'] ?? 'es');

$regParams = ['property' => $propertyId];
if ($checkIn !== '') {
    $regParams['check_in'] = $checkIn;
}
if ($checkOut !== '') {
    $regParams['check_out'] = $checkOut;
}
$regParams['code'] = $uid;
if ($lang !== '') {
    $regParams['lang'] = $lang;
}

$registryUrl = rtrim($publicSiteUrl, '/') . '/registry/?' . http_build_query($regParams);
$guideUrl = rtrim($publicSiteUrl, '/') . '/guide/?' . http_build_query(['code' => $uid, 'lang' => $lang]);
?>

<div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-xs transition-opacity"
         onclick="document.getElementById('modal-container').innerHTML = '';"
         aria-hidden="true"></div>

    <div class="min-h-full flex items-center justify-center p-4 text-center sm:p-0">
        <div class="relative bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:max-w-2xl sm:w-full border border-gray-200">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center <?= $registryCompleted ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                        <?php if ($registryCompleted): ?>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        <?php else: ?>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-gray-900" id="modal-title">
                            <?= $registryCompleted ? 'Guest Registry Dossier' : 'Guest Registry Pending' ?>
                        </h3>
                        <p class="text-xs text-gray-500 font-mono">
                            Reservation UID: <?= htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>
                <button type="button"
                        onclick="document.getElementById('modal-container').innerHTML = '';"
                        class="text-gray-400 hover:text-gray-600 rounded-lg p-1.5 hover:bg-gray-100 transition cursor-pointer">
                    <span class="sr-only">Close</span>
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="px-6 py-6 space-y-6">

                <?php if ($registryCompleted): ?>
                    <!-- COMPLETED DOSSIER -->
                    
                    <!-- Submission Metadata Bar -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-gray-50 p-3 rounded-xl border border-gray-200">
                        <div>
                            <span class="block text-gray-400">Total Guests</span>
                            <span class="font-bold text-gray-800"><?= count($guestsPayload) ?> Occupants</span>
                        </div>
                        <div>
                            <span class="block text-gray-400">Submitted</span>
                            <span class="font-medium text-gray-800"><?= htmlspecialchars($submittedAt, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div>
                            <span class="block text-gray-400">IP Address</span>
                            <span class="font-mono text-gray-800"><?= htmlspecialchars($ipAddress !== '' ? $ipAddress : 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div>
                            <span class="block text-gray-400">Vehicle</span>
                            <span class="font-medium text-gray-800"><?= $carPlates !== '' ? htmlspecialchars($carPlates . ' (' . $carModel . ')', ENT_QUOTES, 'UTF-8') : 'None' ?></span>
                        </div>
                    </div>

                    <!-- Occupants List -->
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Registered Occupants</h4>
                        <div class="space-y-3">
                            <?php foreach ($guestsPayload as $index => $guest): ?>
                                <?php
                                $fullName = (string) ($guest['full_name'] ?? $guest['name'] ?? 'Occupant ' . ($index + 1));
                                $docType = (string) ($guest['doc_type'] ?? 'ID');
                                $docNumber = (string) ($guest['doc_number'] ?? '');
                                $nationality = (string) ($guest['nationality'] ?? 'Not specified');
                                $isPrimary = !empty($guest['is_primary']) || $index === 0;
                                ?>
                                <div class="p-3.5 rounded-xl border border-gray-200 bg-white hover:border-indigo-200 transition">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-2">
                                            <span class="font-semibold text-gray-900 text-sm"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($isPrimary): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-indigo-100 text-indigo-800 uppercase">
                                                    Primary Guest
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600 uppercase">
                                                    Companion
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs font-mono text-gray-700">
                                            <span class="font-semibold text-gray-500"><?= htmlspecialchars($docType, ENT_QUOTES, 'UTF-8') ?>:</span> <?= htmlspecialchars($docNumber, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500 flex items-center space-x-4">
                                        <span>Nationality: <strong class="text-gray-700"><?= htmlspecialchars($nationality, ENT_QUOTES, 'UTF-8') ?></strong></span>
                                        <?php if (!empty($guest['birthdate'])): ?>
                                            <span>Birthdate: <strong class="text-gray-700"><?= htmlspecialchars((string) $guest['birthdate'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Copyable Guest Guide Link (Completed / Unlocked) -->
                        <div class="space-y-1.5 pt-2 border-t border-gray-100">
                            <label for="guide-link-input-completed" class="block text-xs font-semibold text-gray-700">
                                Direct Guest Guide Link (Unlocked Access):
                            </label>
                            <div class="flex rounded-lg shadow-2xs">
                                <input id="guide-link-input-completed"
                                       type="text"
                                       readonly
                                       value="<?= htmlspecialchars($guideUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       class="flex-1 block w-full px-3 py-2 text-xs font-mono bg-gray-50 border border-gray-300 rounded-l-lg select-all focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                <button type="button"
                                        onclick="navigator.clipboard.writeText(document.getElementById('guide-link-input-completed').value).then(() => { this.innerText = 'Copied!'; setTimeout(() => this.innerText = 'Copy Guide Link', 2000); });"
                                        class="inline-flex items-center px-4 py-2 border border-l-0 border-indigo-600 text-xs font-medium rounded-r-lg text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                                    Copy Guide Link
                                </button>
                            </div>
                            <p class="text-[11px] text-gray-400">
                                Direct link for the guest to access apartment and Wi-Fi credentials.
                            </p>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- PENDING STATE -->
                    <div class="text-center py-4">
                        <div class="mx-auto w-12 h-12 rounded-full bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-600 mb-3">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <h4 class="text-base font-semibold text-gray-900">Mandatory Guest Registry Not Submitted</h4>
                        <p class="text-xs text-gray-500 mt-1 max-w-md mx-auto">
                            Per condominium regulations and ADR 0001, Access Credentials cannot be dispatched until all staying occupants are registered.
                        </p>
                    </div>

                    <!-- Guest Contact Details -->
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 text-xs space-y-2">
                        <div class="font-bold text-gray-700 uppercase tracking-wider text-[11px]">Primary Guest Contact</div>
                        <div class="flex justify-between text-gray-600">
                            <span>Name:</span>
                            <span class="font-semibold text-gray-900"><?= htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>Email:</span>
                            <span class="font-mono text-gray-800"><?= htmlspecialchars($guestEmail, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>Phone:</span>
                            <span class="text-gray-800"><?= htmlspecialchars($guestPhone, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <!-- Copyable Registration & Guide Links -->
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <label for="registry-link-input" class="block text-xs font-semibold text-gray-700">
                                Direct Guest Registry Link:
                            </label>
                            <div class="flex rounded-lg shadow-2xs">
                                <input id="registry-link-input"
                                       type="text"
                                       readonly
                                       value="<?= htmlspecialchars($registryUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       class="flex-1 block w-full px-3 py-2 text-xs font-mono bg-gray-50 border border-gray-300 rounded-l-lg select-all focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                <button type="button"
                                        id="copy-registry-btn"
                                        onclick="navigator.clipboard.writeText(document.getElementById('registry-link-input').value).then(() => { this.innerText = 'Copied!'; setTimeout(() => this.innerText = 'Copy Link', 2000); });"
                                        class="inline-flex items-center px-4 py-2 border border-l-0 border-indigo-600 text-xs font-medium rounded-r-lg text-white bg-indigo-600 hover:bg-indigo-700 transition cursor-pointer">
                                    Copy Link
                                </button>
                            </div>
                            <p class="text-[11px] text-gray-400">
                                Send this secure pre-filled link to the Primary Guest via WhatsApp or SMS to expedite their registration.
                            </p>
                        </div>

                        <div class="space-y-1.5">
                            <label for="guide-link-input-pending" class="block text-xs font-semibold text-gray-700">
                                Direct Guest Guide Link:
                            </label>
                            <div class="flex rounded-lg shadow-2xs">
                                <input id="guide-link-input-pending"
                                       type="text"
                                       readonly
                                       value="<?= htmlspecialchars($guideUrl, ENT_QUOTES, 'UTF-8') ?>"
                                       class="flex-1 block w-full px-3 py-2 text-xs font-mono bg-gray-50 border border-gray-300 rounded-l-lg select-all focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                <button type="button"
                                        onclick="navigator.clipboard.writeText(document.getElementById('guide-link-input-pending').value).then(() => { this.innerText = 'Copied!'; setTimeout(() => this.innerText = 'Copy Guide Link', 2000); });"
                                        class="inline-flex items-center px-4 py-2 border border-l-0 border-gray-300 text-xs font-medium rounded-r-lg text-gray-700 bg-gray-100 hover:bg-gray-200 transition cursor-pointer">
                                    Copy Guide Link
                                </button>
                            </div>
                            <p class="text-[11px] text-gray-400">
                                Note: Access credentials remain locked until the guest completes the registry.
                            </p>
                        </div>
                    </div>

                <?php endif; ?>

            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end">
                <button type="button"
                        onclick="document.getElementById('modal-container').innerHTML = '';"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-xs font-medium text-gray-700 bg-white hover:bg-gray-50 transition cursor-pointer">
                    Dismiss
                </button>
            </div>

        </div>
    </div>
</div>
