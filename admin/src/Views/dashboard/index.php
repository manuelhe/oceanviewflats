<?php
/**
 * Master Template: Admin Operational Dashboard Hub
 *
 * @var array<string, mixed> $currentUser
 * @var string $csrfToken
 * @var string $propertyId
 * @var string $hubContentHtml
 */
?>
<div class="space-y-6">
    <!-- Top Greeting Banner -->
    <div class="bg-white overflow-hidden shadow-2xs sm:rounded-xl border border-gray-200 p-6">
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
                <a href="/reservations/new"
                   hx-get="/reservations/new"
                   hx-target="#modal-container"
                   hx-swap="innerHTML"
                   role="button"
                   class="inline-flex items-center px-4 py-2 border border-transparent rounded-lg shadow-2xs text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    + New Reservation
                </a>
            </div>
        </div>
    </div>

    <!-- Reactive Operational Hub Container (Swapped by HTMX on filter change) -->
    <?= $hubContentHtml ?>

    <!-- Modal and Drawer Insertion Targets -->
    <div id="modal-container"></div>
    <div id="drawer-container"></div>
</div>

<script>
function copyRegistryInvite(button) {
    if (!button) return;
    var inviteUrl = button.getAttribute('data-invite-url') || '';
    var guestName = button.getAttribute('data-guest-name') || 'Guest';
    var propId = button.getAttribute('data-prop-id') || '';
    
    var textToCopy = inviteUrl;
    if (inviteUrl && guestName) {
        textToCopy = "Hello " + guestName + ",\n\nPlease complete your guest registration for Apartment " + propId + " prior to arrival using this secure link:\n" + inviteUrl + "\n\nThank you!\nOcean View Flats Team";
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(textToCopy).then(function() {
            showCopyFeedback(button);
        }).catch(function() {
            fallbackCopy(textToCopy, button);
        });
    } else {
        fallbackCopy(textToCopy, button);
    }
}

function fallbackCopy(text, button) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        showCopyFeedback(button);
    } catch (err) {
        console.error('Unable to copy', err);
    }
    document.body.removeChild(textArea);
}

function showCopyFeedback(button) {
    var originalHtml = button.innerHTML;
    button.innerHTML = '<span>✓ Copied!</span>';
    button.classList.add('bg-emerald-600', 'text-white');
    button.classList.remove('bg-indigo-600');
    setTimeout(function() {
        button.innerHTML = originalHtml;
        button.classList.remove('bg-emerald-600');
        button.classList.add('bg-indigo-600');
    }, 2000);
}
</script>
