// Guest Registry Form Interactive Logic
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('registry-form');
    if (!form) return;

    const btnSubmit = document.getElementById('submit-button');
    const txtSubmit = document.getElementById('submit-text');
    const msgBox = document.getElementById('form-message');
    const successOverlay = document.getElementById('success-overlay');
    
    const displayProperty = document.getElementById('display-property');
    const displayCheckIn = document.getElementById('display-check-in');
    const displayCheckOut = document.getElementById('display-check-out');

    const hiddenProperty = document.getElementById('hidden-property');
    const hiddenCheckIn = document.getElementById('hidden-check-in');
    const hiddenCheckOut = document.getElementById('hidden-check-out');

    const addGuestBtn = document.getElementById('add-guest-button');
    const guestCountInput = document.getElementById('guest-count-input');
    const captchaLabel = document.getElementById('captcha-label');
    const captchaChallenge = document.getElementById('captcha-challenge');
    const captchaResponse = document.getElementById('captcha-response');

    // Retrieve localized message attributes or defaults
    const lang = document.documentElement.lang || 'en';
    const errorMsgs = {
        en: "Something went wrong. Please check the fields and try again.",
        es: "Algo salió mal. Por favor, verifique los campos e intente de nuevo.",
        fr: "Une erreur est survenue. Veuillez vérifier les champs et réessayer.",
        it: "Qualcosa è andato storto. Verificare i campi e riprovare.",
        de: "Etwas ist schiefgelaufen. Bitte überprüfen Sie die Felder.",
        ja: "エラーが発生しました。入力内容を確認してやり直してください。"
    };
    const submittingMsgs = {
        en: "Registering...",
        es: "Registrando...",
        fr: "Enregistrement...",
        it: "Registrazione...",
        de: "Registrierung...",
        ja: "登録中..."
    };

    const defaultErrorMsg = errorMsgs[lang] || errorMsgs.en;
    const submittingMsg = submittingMsgs[lang] || submittingMsgs.en;

    // 1. Read URL query params and populate stay details
    const urlParams = new URLSearchParams(window.location.search);
    const checkInVal = urlParams.get('check_in') || '';
    const checkOutVal = urlParams.get('check_out') || '';
    const propertyVal = urlParams.get('property') || '';

    // Show details to user and populate hidden form fields
    if (propertyVal) {
        displayProperty.textContent = `OceanViewFlats ${propertyVal}`;
        hiddenProperty.value = propertyVal;
    } else {
        displayProperty.textContent = "OceanViewFlats (Not Specified)";
        hiddenProperty.value = "";
    }

    if (checkInVal) {
        displayCheckIn.textContent = checkInVal;
        hiddenCheckIn.value = checkInVal;
    } else {
        displayCheckIn.textContent = "Not Specified";
        hiddenCheckIn.value = "";
    }

    if (checkOutVal) {
        displayCheckOut.textContent = checkOutVal;
        hiddenCheckOut.value = checkOutVal;
    } else {
        displayCheckOut.textContent = "Not Specified";
        hiddenCheckOut.value = "";
    }

    // 2. Manage Dynamic Guest Cards (up to 6 guests)
    let currentGuestCount = 1;

    // Helper to update dynamic guest card input states (enable/disable for submission correctness)
    function updateGuestCards() {
        for (let num = 1; num <= 6; num++) {
            const card = document.getElementById(`guest-card-${num}`);
            if (!card) continue;

            const inputs = card.querySelectorAll('input, select');
            if (num <= currentGuestCount) {
                // Show card
                card.classList.remove('hidden');
                // Enable inputs & make them required (except guest 1 has age/name required by HTML already)
                inputs.forEach(input => {
                    input.removeAttribute('disabled');
                    input.setAttribute('required', 'required');
                });
            } else {
                // Hide card
                card.classList.add('hidden');
                // Disable inputs so they are not sent in POST, and clear them
                inputs.forEach(input => {
                    input.setAttribute('disabled', 'disabled');
                    input.removeAttribute('required');
                    if (input.tagName === 'INPUT') input.value = '';
                });
            }
        }

        // Set hidden guest count input value
        guestCountInput.value = currentGuestCount;

        // Hide add button if maximum (6) reached
        if (currentGuestCount >= 6) {
            addGuestBtn.classList.add('hidden');
        } else {
            addGuestBtn.classList.remove('hidden');
        }
    }

    // Add guest button handler
    addGuestBtn.addEventListener('click', () => {
        if (currentGuestCount < 6) {
            currentGuestCount++;
            updateGuestCards();
            // Smooth scroll to the newly added guest card
            const newCard = document.getElementById(`guest-card-${currentGuestCount}`);
            if (newCard) {
                newCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    });

    // Remove guest handlers (attached using event delegation on the form)
    form.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('[data-remove-guest]');
        if (!removeBtn) return;

        const numToRemove = parseInt(removeBtn.getAttribute('data-remove-guest'), 10);
        if (numToRemove && numToRemove > 1) {
            // Shift values up if removing a guest in the middle
            for (let i = numToRemove; i < currentGuestCount; i++) {
                const currentName = document.getElementById(`guest-name-${i}`);
                const nextName = document.getElementById(`guest-name-${i+1}`);
                const currentAge = document.getElementById(`guest-age-${i}`);
                const nextAge = document.getElementById(`guest-age-${i+1}`);
                const currentDocType = document.getElementById(`guest-doc-type-${i}`);
                const nextDocType = document.getElementById(`guest-doc-type-${i+1}`);
                const currentDocNum = document.getElementById(`guest-doc-num-${i}`);
                const nextDocNum = document.getElementById(`guest-doc-num-${i+1}`);

                if (currentName && nextName) currentName.value = nextName.value;
                if (currentAge && nextAge) currentAge.value = nextAge.value;
                if (currentDocType && nextDocType) currentDocType.value = nextDocType.value;
                if (currentDocNum && nextDocNum) currentDocNum.value = nextDocNum.value;
            }

            // Decrement and refresh UI states
            currentGuestCount--;
            updateGuestCards();
        }
    });

    // Initialize the guest cards correct disabled states
    updateGuestCards();

    // 3. Dynamic Math Captcha fetch
    let captchaSignature = '';
    async function loadCaptcha() {
        try {
            const actionPath = form.getAttribute('action') || 'api/registry-processor.php';
            const processorBase = actionPath.replace('registry-processor.php', '');
            
            // Re-use the captcha endpoint from contact-processor.php since it has CAPTCHA_SECRET config!
            const currentLang = document.documentElement.lang || 'en';
            const response = await fetch(processorBase + 'contact-processor.php?action=captcha&lang=' + currentLang);
            if (response.ok) {
                const data = await response.json();
                const originalText = captchaLabel.getAttribute('data-original') || captchaLabel.textContent;
                if (!captchaLabel.getAttribute('data-original')) {
                    captchaLabel.setAttribute('data-original', originalText);
                }
                captchaLabel.textContent = `${originalText} (${data.challenge})`;
                captchaChallenge.value = data.challenge;
                captchaSignature = data.signature;
                captchaResponse.value = '';
            }
        } catch (err) {
            console.error('Error loading captcha:', err);
        }
    }

    // Initial load of Captcha
    loadCaptcha();

    // Helper to display error messages
    function showMsg(message, isSuccess = false) {
        msgBox.className = isSuccess 
            ? 'p-4 rounded-2xl text-sm font-medium mb-6 bg-emerald-50 text-emerald-800 border border-emerald-200' 
            : 'p-4 rounded-2xl text-sm font-medium mb-6 bg-rose-50 text-rose-800 border border-rose-200';
        msgBox.textContent = message;
        msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // 4. Form Submit Handler
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Clear messages
        msgBox.className = 'hidden';
        msgBox.textContent = '';

        // Front-end Validation (check visible inputs)
        const visibleInputs = form.querySelectorAll('input:not([disabled]):not([type="hidden"]), select:not([disabled])');
        let isValid = true;
        visibleInputs.forEach(input => {
            if (input.hasAttribute('required') && !input.value.trim()) {
                isValid = false;
                input.classList.add('border-red-400');
                input.addEventListener('input', function removeRed() {
                    input.classList.remove('border-red-400');
                    input.removeEventListener('input', removeRed);
                });
            }
        });

        if (!isValid) {
            showMsg(defaultErrorMsg, false);
            return;
        }

        // Prepare Form Data
        const formData = new FormData(form);
        formData.append('lang', document.documentElement.lang || 'en');
        formData.append('captcha_signature', captchaSignature);

        // Submitting State
        btnSubmit.disabled = true;
        txtSubmit.textContent = submittingMsg;
        btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');

        try {
            const actionUrl = form.getAttribute('action') || 'api/registry-processor.php';
            const response = await fetch(actionUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (response.ok && result.success) {
                // Mark registration as completed for this specific reservation (property, check-in, check-out)
                try {
                    const cleanPropNo = propertyVal.replace(/\D/g, '');
                    const checkInParam = urlParams.get('check_in') || urlParams.get('checkin') || 'unspecified';
                    const checkOutParam = urlParams.get('check_out') || urlParams.get('checkout') || 'unspecified';
                    const stayKey = `stay_reg_${cleanPropNo || '1606'}_${checkInParam.replace(/\s+/g, '_')}_${checkOutParam.replace(/\s+/g, '_')}`;
                    localStorage.setItem(stayKey, 'completed');
                } catch (err) {
                    console.error('Error saving stay registration status:', err);
                }

                // Success: Hide form and show success message
                form.classList.add('opacity-0');
                setTimeout(() => {
                    form.classList.add('hidden');
                    successOverlay.classList.remove('hidden');
                    successOverlay.classList.add('animate-fade-in');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }, 300);

                // Send a custom Matomo conversion event if available
                if (window._paq) {
                    window._paq.push(['trackEvent', 'Guest Registry', 'Submission Success', `Property: ${propertyVal}, Guests: ${currentGuestCount}`]);
                    window._paq.push(['trackGoal', 5]); // Registry Completed Goal
                }
            } else {
                const errorVal = result.message || defaultErrorMsg;
                showMsg(errorVal, false);

                if (window._paq) {
                    window._paq.push(['trackEvent', 'Guest Registry', 'Submission Failure', errorVal]);
                }

                // Reset submit button state
                btnSubmit.disabled = false;
                txtSubmit.textContent = btnSubmit.getAttribute('data-original-text') || "Complete Guest Registration";
                btnSubmit.classList.remove('opacity-75', 'cursor-not-allowed');
                loadCaptcha();
            }
        } catch (err) {
            console.error('Error submitting form:', err);
            showMsg(defaultErrorMsg, false);

            btnSubmit.disabled = false;
            txtSubmit.textContent = btnSubmit.getAttribute('data-original-text') || "Complete Guest Registration";
            btnSubmit.classList.remove('opacity-75', 'cursor-not-allowed');
            loadCaptcha();
        }
    });

    // Store original submit button text
    btnSubmit.setAttribute('data-original-text', txtSubmit.textContent);
});
