// Guest Welcome Guide Dynamic Interactive Logic
document.addEventListener('DOMContentLoaded', () => {
    // 1. Core DOM Elements
    const greetingBox = document.getElementById('guide-greeting-box');
    const displayApartment = document.getElementById('display-apartment');
    const displayCheckIn = document.getElementById('display-check-in');
    const displayCheckOut = document.getElementById('display-check-out');
    const displayDoorCode = document.getElementById('display-door-code');
    const displayWifiSSID = document.getElementById('display-wifi-ssid');
    const displayWifiPassword = document.getElementById('display-wifi-password');
    const registryLink = document.getElementById('registry-link');
    const copyAlert = document.getElementById('copy-alert');
    const copyAlertText = document.getElementById('copy-alert-text');

    // 2. Language & Localized Defaults
    const lang = document.documentElement.lang || 'en';

    const defaultGuests = {
        en: "Guest",
        es: "Huésped",
        fr: "Voyageur",
        it: "Ospite",
        de: "Gast",
        ja: "ゲスト"
    };

    const defaultDates = {
        en: "Not Specified",
        es: "No especificado",
        fr: "Non spécifiée",
        it: "Non specificato",
        de: "Nicht angegeben",
        ja: "未指定"
    };

    const introTemplates = {
        en: "Welcome to your beachside home, {guestName}! We are absolutely thrilled to host you and hope you have a wonderful, relaxing, and unforgettable stay.",
        es: "¡Te damos una cálida bienvenida a tu hogar frente al mar, {guestName}! Estamos muy felices de hospedarte y esperamos que tengas una estadía maravillosa, relajante e inolvidable.",
        fr: "Bienvenue dans votre havre de paix au bord de la mer, {guestName} ! Nous sommes ravis de vous accueillir et vous souhaitons un séjour merveilleux, relaxant et inoubliable.",
        it: "Benvenuto nella tua casa in riva al mare, {guestName}! Siamo felici di ospitarti e speriamo che tu possa trascorrere un soggiorno meraviglioso, rilassante e indimenticabile.",
        de: "Herzlich willkommen in Ihrem Zuhause am Meer, {guestName}! Wir freuen uns sehr, Sie als Gast zu haben, und wünschen Ihnen einen wunderbaren, erholsamen und unvergesslichen Aufenthalt.",
        ja: "{guestName}様、海辺のマイホームへようこそ！ご宿泊いただき大変嬉しく思います。リラックスできる素晴らしい、忘れられない滞在となりますように。"
    };

    const copiedMsgs = {
        en: "Copied to clipboard!",
        es: "¡Copiado al portapapeles!",
        fr: "Copié dans le presse-papiers!",
        it: "Copiato negli appunti!",
        de: "In die Zwischenablage kopiert!",
        ja: "クリップボードにコピーしました！"
    };

    // 3. Read Query Parameters
    const urlParams = new URLSearchParams(window.location.search);
    const guestName = urlParams.get('guest') || urlParams.get('name') || defaultGuests[lang] || defaultGuests.en;
    const propertyNumber = urlParams.get('property') || urlParams.get('prop') || urlParams.get('apt') || "1606";
    const checkIn = urlParams.get('check_in') || urlParams.get('checkin') || defaultDates[lang] || defaultDates.en;
    const checkOut = urlParams.get('check_out') || urlParams.get('checkout') || defaultDates[lang] || defaultDates.en;
    const doorPassword = urlParams.get('code') || urlParams.get('password') || urlParams.get('pass') || propertyNumber;

    // 4. Update DOM Elements Dynamically
    
    // Greeting
    if (greetingBox) {
        const template = introTemplates[lang] || introTemplates.en;
        greetingBox.textContent = template.replace('{guestName}', guestName);
    }

    // Apartment & Address labels
    if (displayApartment) {
        displayApartment.textContent = `OceanViewFlats ${propertyNumber}`;
    }

    // Dates
    if (displayCheckIn) {
        displayCheckIn.textContent = checkIn;
    }
    if (displayCheckOut) {
        displayCheckOut.textContent = checkOut;
    }

    // Door lock code (append # as per guide instructions)
    if (displayDoorCode) {
        // If password already has #, don't append another one
        const formattedCode = doorPassword.endsWith('#') ? doorPassword : `${doorPassword}#`;
        displayDoorCode.textContent = formattedCode;
    }

    // Wifi SSID and Password
    const cleanProp = propertyNumber.replace(/\D/g, ''); // Ensure only numbers
    const wifiSSID = `APTO${cleanProp || '1606'}`;
    const wifiPass = `Invitado@${cleanProp || '1606'}@HN`;

    if (displayWifiSSID) {
        displayWifiSSID.textContent = wifiSSID;
    }
    if (displayWifiPassword) {
        displayWifiPassword.textContent = wifiPass;
    }

    // Registry Dynamic URL
    if (registryLink) {
        // Construct asset prefix dynamically or read from layout
        const pathPrefix = document.getElementById('btn-copy-door-code') ? '../' : './';
        const pageName = lang === 'en' ? 'registry/index.html' : `registry/${lang}.html`;
        
        // Pass same parameters down to registry page
        const regParams = new URLSearchParams();
        regParams.set('property', propertyNumber);
        regParams.set('lang', lang);
        if (urlParams.get('check_in') || urlParams.get('checkin')) {
            regParams.set('check_in', checkIn);
        }
        if (urlParams.get('check_out') || urlParams.get('checkout')) {
            regParams.set('check_out', checkOut);
        }
        
        registryLink.href = `${pathPrefix}${pageName}?${regParams.toString()}`;
    }

    // 4.5 Hide guest registry banner if already completed for this specific reservation
    const cleanPropNo = propertyNumber.replace(/\D/g, '');
    const checkInParam = urlParams.get('check_in') || urlParams.get('checkin') || 'unspecified';
    const checkOutParam = urlParams.get('check_out') || urlParams.get('checkout') || 'unspecified';
    const stayKey = `stay_reg_${cleanPropNo || '1606'}_${checkInParam.replace(/\s+/g, '_')}_${checkOutParam.replace(/\s+/g, '_')}`;

    const registryBanner = document.getElementById('guide-registry-banner');
    if (registryBanner && localStorage.getItem(stayKey) === 'completed') {
        registryBanner.style.display = 'none';
    }

    // 5. Setup Copy Event Listeners
    const copyButtons = [
        document.getElementById('btn-copy-door-code'),
        document.getElementById('btn-copy-wifi-ssid'),
        document.getElementById('btn-copy-wifi-pass')
    ];

    copyButtons.forEach(btn => {
        if (!btn) return;
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const targetId = btn.getAttribute('data-copy-target');
            const targetEl = document.getElementById(targetId);
            if (!targetEl) return;

            const textToCopy = targetEl.textContent.trim();

            navigator.clipboard.writeText(textToCopy)
                .then(() => {
                    // Show custom copy toast notification
                    if (copyAlert) {
                        copyAlertText.textContent = copiedMsgs[lang] || copiedMsgs.en;
                        copyAlert.classList.remove('opacity-0', 'translate-y-24');
                        copyAlert.classList.add('opacity-100', 'translate-y-0');

                        // Animate button feedback
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
