// Guest Welcome Guide Dynamic Interactive Logic & Secure Server-Gated Credential Island
document.addEventListener('DOMContentLoaded', () => {
    // 1. Core DOM Elements
    const greetingBox = document.getElementById('guide-greeting-box');
    const displayApartment = document.getElementById('display-apartment');
    const displayCheckIn = document.getElementById('display-check-in');
    const displayCheckOut = document.getElementById('display-check-out');
    const displayDoorCode = document.getElementById('display-door-code');
    const displayWifiSSID = document.getElementById('display-wifi-ssid');
    const displayWifiPassword = document.getElementById('display-wifi-password');
    const displayParkingSpot = document.getElementById('display-parking-spot');
    const btnCopyDoorCode = document.getElementById('btn-copy-door-code');
    const btnCopyWifiSSID = document.getElementById('btn-copy-wifi-ssid');
    const btnCopyWifiPass = document.getElementById('btn-copy-wifi-pass');
    const btnCopyParkingSpot = document.getElementById('btn-copy-parking-spot');
    const registryBanner = document.getElementById('guide-registry-banner');
    const registryLink = document.getElementById('registry-link');
    const credentialLockedNotice = document.getElementById('credential-locked-notice');
    const credentialLockedTitle = document.getElementById('credential-locked-title');
    const credentialLockedDesc = document.getElementById('credential-locked-desc');
    const credentialLockedBtn = document.getElementById('credential-locked-btn');
    const wifiLockedNotice = document.getElementById('wifi-locked-notice');
    const parkingLockedNotice = document.getElementById('parking-locked-notice');
    const parkingCard = document.getElementById('parking-card');
    const copyAlert = document.getElementById('copy-alert');
    const copyAlertText = document.getElementById('copy-alert-text');
    const doorCodeCard = document.getElementById('door-code-card');

    // 2. Language & Localized Strings from DOM Data Attributes (Rule: No hardcoded client JS literals)
    const lang = document.documentElement.lang || 'en';
    const msgLocked = doorCodeCard?.getAttribute('data-msg-locked') || 'Access Locked (Registry Required)';
    const msgLockedDesc = doorCodeCard?.getAttribute('data-msg-locked-desc') || 'Per building security and Colombian regulations, door codes and Wi-Fi credentials are only released after submitting the Guest Registry.';
    const msgActionUnlock = doorCodeCard?.getAttribute('data-msg-action-unlock') || 'Complete Registry to Unlock';
    const msgVerifying = doorCodeCard?.getAttribute('data-msg-verifying') || 'Verifying access permissions...';
    const msgNotFound = doorCodeCard?.getAttribute('data-msg-not-found') || 'Please provide a valid reservation code or link from your confirmation email.';
    const msgCopied = doorCodeCard?.getAttribute('data-msg-copied') || 'Copied!';
    const msgParkingPlaceholder = parkingCard?.getAttribute('data-msg-placeholder') || '--';

    const introTemplates = {
        en: "Welcome to your beachside home, {guestName}! We are absolutely thrilled to host you and hope you have a wonderful, relaxing, and unforgettable stay.",
        es: "¡Te damos una cálida bienvenida a tu hogar frente al mar, {guestName}! Estamos muy felices de hospedarte y esperamos que tengas una estadía maravillosa, relajante e inolvidable.",
        fr: "Bienvenue dans votre havre de paix au bord de la mer, {guestName} ! Nous sommes ravis de vous accueillir et vous souhaitons un séjour merveilleux, relaxant et inoubliable.",
        it: "Benvenuto nella tua casa in riva al mare, {guestName}! Siamo felici di ospitarti e speriamo che tu possa trascorrere un soggiorno meraviglioso, rilassante e indimenticabile.",
        de: "Herzlich willkommen in Ihrem Zuhause am Meer, {guestName}! Wir freuen uns sehr, Sie als Gast zu haben, und wünschen Ihnen einen wunderbaren, erholsamen und unvergesslichen Aufenthalt.",
        ja: "{guestName}様、海辺のマイホームへようこそ！ご宿泊いただき大変嬉しく思います。リラックスできる素晴らしい、忘れられない滞在となりますように。"
    };

    // 3. Read Query Parameters (Strictly reservation identifiers, no sensitive plaintext passwords)
    const urlParams = new URLSearchParams(window.location.search);
    const reservationCode = (urlParams.get('code') || urlParams.get('token') || '').trim();
    const propertyNumber = (urlParams.get('property') || urlParams.get('prop') || urlParams.get('apt') || '1606').replace(/\D/g, '') || '1606';
    const rawGuestName = urlParams.get('guest') || urlParams.get('name') || '';
    const rawCheckIn = urlParams.get('check_in') || urlParams.get('checkin') || '';
    const rawCheckOut = urlParams.get('check_out') || urlParams.get('checkout') || '';

    // Relative asset path calculation for endpoints and cross-links (guide views are located in /guide/)
    const pathPrefix = '../';
    const registryPageName = lang === 'en' ? 'registry/index.html' : `registry/${lang}.html`;

    // Safely sanitize URLs before assigning to href
    function sanitizeUrl(url) {
        if (!url || typeof url !== 'string') return '#';
        const trimmed = url.trim();
        if (trimmed.startsWith('/') || trimmed.startsWith('./') || trimmed.startsWith('../')) {
            return trimmed;
        }
        try {
            const parsed = new URL(trimmed, window.location.origin);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return parsed.href;
            }
        } catch (_) {
            return '#';
        }
        return '#';
    }

    // Construct default prefilled registry link
    function buildRegistryUrl(codeOverride) {
        const regParams = new URLSearchParams();
        regParams.set('property', propertyNumber);
        regParams.set('lang', lang);
        if (codeOverride || reservationCode) {
            regParams.set('code', codeOverride || reservationCode);
        }
        if (rawCheckIn) {
            regParams.set('check_in', rawCheckIn);
        }
        if (rawCheckOut) {
            regParams.set('check_out', rawCheckOut);
        }
        return `${pathPrefix}${registryPageName}?${regParams.toString()}`;
    }

    const defaultRegistryUrl = buildRegistryUrl();

    // 4. Helper Functions for UI State

    function setInitialDisplayDetails(name, checkIn, checkOut, prop) {
        if (displayApartment) {
            displayApartment.textContent = `OceanViewFlats ${prop || propertyNumber}`;
        }
        if (displayCheckIn && checkIn) {
            displayCheckIn.textContent = checkIn;
        }
        if (displayCheckOut && checkOut) {
            displayCheckOut.textContent = checkOut;
        }
        if (greetingBox && name) {
            const template = introTemplates[lang] || introTemplates.en;
            greetingBox.textContent = template.replace('{guestName}', name);
        }
    }

    function setLockedState(targetRegistryUrl, reasonMsg) {
        if (displayDoorCode) {
            displayDoorCode.textContent = '••••••';
        }
        if (displayWifiSSID) {
            displayWifiSSID.textContent = '••••••';
        }
        if (displayWifiPassword) {
            displayWifiPassword.textContent = '••••••';
        }
        if (displayParkingSpot) {
            displayParkingSpot.textContent = '••••••';
        }

        // Disable copy actions
        [btnCopyDoorCode, btnCopyWifiSSID, btnCopyWifiPass, btnCopyParkingSpot].forEach(btn => {
            if (btn) {
                btn.disabled = true;
                btn.setAttribute('aria-disabled', 'true');
                btn.classList.add('opacity-40', 'cursor-not-allowed');
            }
        });

        // Show locked notices
        if (credentialLockedNotice) {
            credentialLockedNotice.classList.remove('hidden');
        }
        if (wifiLockedNotice) {
            wifiLockedNotice.classList.remove('hidden');
        }
        if (parkingLockedNotice) {
            parkingLockedNotice.classList.remove('hidden');
        }
        if (credentialLockedDesc && reasonMsg) {
            credentialLockedDesc.textContent = reasonMsg;
        }

        const effectiveRegistryUrl = sanitizeUrl(targetRegistryUrl || defaultRegistryUrl);
        if (registryLink) {
            registryLink.href = effectiveRegistryUrl;
        }
        if (credentialLockedBtn) {
            credentialLockedBtn.href = effectiveRegistryUrl;
        }
        if (registryBanner) {
            registryBanner.style.display = '';
        }
    }

    function setUnlockedState(credentials, reservation) {
        if (displayDoorCode && credentials && credentials.door_code) {
            const doorCode = credentials.door_code;
            displayDoorCode.textContent = doorCode.endsWith('#') ? doorCode : `${doorCode}#`;
        }
        if (displayWifiSSID && credentials && credentials.wifi_ssid) {
            displayWifiSSID.textContent = credentials.wifi_ssid;
        }
        if (displayWifiPassword && credentials && credentials.wifi_password) {
            displayWifiPassword.textContent = credentials.wifi_password;
        }
        if (displayParkingSpot) {
            if (credentials && credentials.parking_spot) {
                const spot = credentials.parking_spot;
                displayParkingSpot.textContent = spot.startsWith('#') ? spot : `#${spot}`;
                if (btnCopyParkingSpot) {
                    btnCopyParkingSpot.disabled = false;
                    btnCopyParkingSpot.removeAttribute('aria-disabled');
                    btnCopyParkingSpot.classList.remove('opacity-40', 'cursor-not-allowed');
                }
            } else {
                displayParkingSpot.textContent = msgParkingPlaceholder;
                if (btnCopyParkingSpot) {
                    btnCopyParkingSpot.disabled = true;
                    btnCopyParkingSpot.setAttribute('aria-disabled', 'true');
                    btnCopyParkingSpot.classList.add('opacity-40', 'cursor-not-allowed');
                }
            }
        }

        // Enable copy actions
        [btnCopyDoorCode, btnCopyWifiSSID, btnCopyWifiPass].forEach(btn => {
            if (btn) {
                btn.disabled = false;
                btn.removeAttribute('aria-disabled');
                btn.classList.remove('opacity-40', 'cursor-not-allowed');
            }
        });

        // Hide locked notices
        if (credentialLockedNotice) {
            credentialLockedNotice.classList.add('hidden');
        }
        if (wifiLockedNotice) {
            wifiLockedNotice.classList.add('hidden');
        }
        if (parkingLockedNotice) {
            parkingLockedNotice.classList.add('hidden');
        }

        // Hide registry callout banner since guest registry is already completed
        if (registryBanner) {
            registryBanner.style.display = 'none';
        }

        // Update reservation details if returned
        if (reservation) {
            setInitialDisplayDetails(
                reservation.guest_name,
                reservation.check_in,
                reservation.check_out,
                reservation.property_id
            );
        }
    }

    // 5. Populate initial display from URL parameters
    setInitialDisplayDetails(rawGuestName, rawCheckIn, rawCheckOut, propertyNumber);

    // Initial state: locked by default until verified
    setLockedState(defaultRegistryUrl, reservationCode ? msgVerifying : msgNotFound);

    // 6. Asynchronous Verification against Server-Gated Endpoint (ADR 0001)
    if (!reservationCode) {
        // No reservation code provided: access remains locked
        setLockedState(defaultRegistryUrl, msgNotFound);
    } else {
        const apiUrl = `${pathPrefix}api/guide-access.php?code=${encodeURIComponent(reservationCode)}&lang=${encodeURIComponent(lang)}`;

        fetch(apiUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(response => {
            return response.json().then(data => ({
                status: response.status,
                data: data
            }));
        })
        .then(({ status, data }) => {
            if (status === 200 && data.verified && data.credentials) {
                // Access granted: Registry verified and reservation confirmed
                setUnlockedState(data.credentials, data.reservation);
            } else if (data.status === 'registry_required') {
                // Reservation valid and confirmed, but Guest Registry not yet submitted
                if (data.reservation) {
                    setInitialDisplayDetails(
                        data.reservation.guest_name,
                        data.reservation.check_in,
                        data.reservation.check_out,
                        data.reservation.property_id
                    );
                }
                let dynamicRegUrl = defaultRegistryUrl;
                if (data.registry_url) {
                    if (data.registry_url.startsWith('http://') || data.registry_url.startsWith('https://')) {
                        dynamicRegUrl = data.registry_url;
                    } else {
                        dynamicRegUrl = `${pathPrefix}${data.registry_url.replace(/^\//, '')}`;
                    }
                }
                setLockedState(dynamicRegUrl, data.message || msgLockedDesc);
            } else {
                // Unauthorized / cancelled / not found
                setLockedState(defaultRegistryUrl, data.message || msgNotFound);
            }
        })
        .catch(err => {
            console.error('Error verifying guide access:', err);
            // On network failure, retain safe locked state
            setLockedState(defaultRegistryUrl, msgLockedDesc);
        });
    }

    // 7. Clipboard Copy Functionality
    const copyButtons = [
        btnCopyDoorCode,
        btnCopyWifiSSID,
        btnCopyWifiPass,
        btnCopyParkingSpot
    ];

    copyButtons.forEach(btn => {
        if (!btn) return;
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (btn.disabled || btn.classList.contains('cursor-not-allowed')) {
                return;
            }

            const targetId = btn.getAttribute('data-copy-target');
            const targetEl = document.getElementById(targetId);
            if (!targetEl) return;

            const textToCopy = targetEl.textContent.trim();
            if (textToCopy === '--' || textToCopy === '••••••' || textToCopy === msgParkingPlaceholder) {
                return;
            }

            navigator.clipboard.writeText(textToCopy)
                .then(() => {
                    if (copyAlert) {
                        copyAlertText.textContent = msgCopied;
                        copyAlert.classList.remove('opacity-0', 'translate-y-24');
                        copyAlert.classList.add('opacity-100', 'translate-y-0');

                        const originalHtml = btn.innerHTML;
                        btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-emerald-400"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
                        
                        setTimeout(() => {
                            copyAlert.classList.remove('opacity-100', 'translate-y-0');
                            copyAlert.classList.add('opacity-0', 'translate-y-24');
                            btn.innerHTML = originalHtml;
                        }, 2000);
                    }
                })
                .catch(err => {
                    console.error('Failed to copy text: ', err);
                });
        });
    });
});
