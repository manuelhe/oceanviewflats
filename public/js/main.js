// Matomo
let _paq = window._paq = window._paq || [];
/* tracker methods like "setCustomDimension" should be called before "trackPageView" */
_paq.push(['trackPageView']);
_paq.push(['enableLinkTracking']);
(function() {
    let u="//stats.fractalserver.com/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '2']);
    let d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
})();

// Simple scroll effect for navbar
window.addEventListener('scroll', () => {
    const nav = document.getElementById('navbar');
    const logo = document.getElementById('nav-logo');
    const toggle = document.getElementById('lang-toggle');
    const contact = document.getElementById('nav-contact');
    if (!nav || !logo || !toggle) return;
    
    if (window.scrollY > 50) {
        nav.classList.add('bg-white/90', 'backdrop-blur-md', 'shadow-sm', 'py-3');
        nav.classList.remove('bg-transparent', 'py-5');
        logo.classList.add('text-[#FF5A5F]');
        logo.classList.remove('text-white');
        toggle.classList.add('bg-slate-100', 'hover:bg-slate-200', 'text-slate-700');
        toggle.classList.remove('bg-white/20', 'hover:bg-white/30', 'text-white', 'backdrop-blur-sm');
        if (contact) {
            contact.classList.add('text-slate-600');
            contact.classList.remove('text-white');
        }
    } else {
        nav.classList.remove('bg-white/90', 'backdrop-blur-md', 'shadow-sm', 'py-3');
        nav.classList.add('bg-transparent', 'py-5');
        logo.classList.remove('text-[#FF5A5F]');
        logo.classList.add('text-white');
        toggle.classList.remove('bg-slate-100', 'hover:bg-slate-200', 'text-slate-700');
        toggle.classList.add('bg-white/20', 'hover:bg-white/30', 'text-white', 'backdrop-blur-sm');
        if (contact) {
            contact.classList.remove('text-slate-600');
            contact.classList.add('text-white');
        }
    }
});

// Language Dropdown Mobile Toggle
document.addEventListener('DOMContentLoaded', () => {
    // Save language preference if we are on a localized page
    const currentLang = document.documentElement.lang;
    if (currentLang) {
        localStorage.setItem('lang-pref', currentLang);
    }

    const langToggleGroup = document.getElementById('lang-toggle-group');
    if (!langToggleGroup) return;
    
    // Select all language links
    const langLinks = langToggleGroup.querySelectorAll('a');
    
    // Dynamically append current query parameters if present
    const searchParams = window.location.search;
    if (searchParams) {
        langLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && !href.includes('?')) {
                link.setAttribute('href', href + searchParams);
            }
        });
    }

    // Save language preference on click
    langLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');
            let clickedLang = 'en';
            if (href.includes('.html')) {
                clickedLang = href.replace('.html', '');
            }
            localStorage.setItem('lang-pref', clickedLang);
        });
    });

    // Select the dropdown menu inside the group
    const dropdownMenu = langToggleGroup.querySelector('.absolute.right-0.top-full');
    if (!dropdownMenu) return;

    langToggleGroup.addEventListener('click', (e) => {
        // Toggle the opacity and visibility classes
        const isVisible = dropdownMenu.classList.contains('opacity-100');
        
        if (isVisible) {
            dropdownMenu.classList.remove('opacity-100', 'visible');
            dropdownMenu.classList.add('opacity-0', 'invisible');
        } else {
            dropdownMenu.classList.remove('opacity-0', 'invisible');
            dropdownMenu.classList.add('opacity-100', 'visible');
        }
    });

    // Close when clicking outside
    document.addEventListener('click', (e) => {
        if (!langToggleGroup.contains(e.target)) {
            dropdownMenu.classList.remove('opacity-100', 'visible');
            dropdownMenu.classList.add('opacity-0', 'invisible');
        }
    });
});

// Calendar Logic
document.addEventListener('DOMContentLoaded', () => {
    const widget = document.getElementById('calendar-widget');
    if (!widget) return;

    let currentDate = new Date();
    let checkIn = null;
    let checkOut = null;
    let blockedDates = []; // Array of YYYY-MM-DD strings of booked dates
    
    const lang = widget.getAttribute('data-lang');
    const monthNames = JSON.parse(widget.getAttribute('data-month-names'));
    const dayNames = JSON.parse(widget.getAttribute('data-day-names'));

    const btnPrev = document.getElementById('btn-prev-month');
    const btnNext = document.getElementById('btn-next-month');
    const monthLabel = document.getElementById('month-label');
    const grid = document.getElementById('calendar-grid');
    
    const dpIn = document.getElementById('check-in-display');
    const dpOut = document.getElementById('check-out-display');
    const btnBook = document.getElementById('btn-book');
    const btnBookText = document.getElementById('btn-book-text');
    const airbnbUrl = btnBook.getAttribute('data-airbnb-url');
    const txtReady = btnBook.getAttribute('data-text-ready');
    const txtDefault = btnBook.getAttribute('data-text-default');

    // Retrieve property ID from page path (defaults to 1707 if not 1606)
    const propertyId = window.location.pathname.includes('1606') ? '1606' : '1707';

    // Fetch live blocked dates from local caching proxy
    async function fetchBlockedDates() {
        try {
            const response = await fetch(`/api/availability.php?property=${propertyId}`);
            if (response.ok) {
                blockedDates = await response.json();
                renderCalendar();
            }
        } catch (err) {
            console.error('Error retrieving live Airbnb calendar blocked dates:', err);
        }
    }

    function renderCalendar() {
        grid.innerHTML = '';
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        
        monthLabel.textContent = monthNames[lang][month] + ' ' + year;

        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date();
        today.setHours(0,0,0,0);

        for (let i = 0; i < firstDay; i++) {
            const empty = document.createElement('div');
            empty.className = 'w-8 h-8 md:w-10 md:h-10';
            grid.appendChild(empty);
        }

        for (let i = 1; i <= daysInMonth; i++) {
            const btn = document.createElement('button');
            const thisDate = new Date(year, month, i);
            const dateStr = formatDate(thisDate);
            
            const isPast = thisDate < today;
            const isBlocked = blockedDates.includes(dateStr);
            const isCheckIn = checkIn && thisDate.getTime() === checkIn.getTime();
            const isCheckOut = checkOut && thisDate.getTime() === checkOut.getTime();
            const isBetween = checkIn && checkOut && thisDate > checkIn && thisDate < checkOut;

            let bgClass = "bg-white hover:bg-slate-100 text-slate-700";
            if (isPast) {
                bgClass = "bg-transparent text-slate-300 cursor-not-allowed";
            } else if (isBlocked) {
                bgClass = "bg-slate-50 text-slate-300 cursor-not-allowed line-through relative after:content-[''] after:absolute after:w-full after:h-[1px] after:bg-slate-300 after:rotate-[-45deg]";
            } else if (isCheckIn || isCheckOut) {
                bgClass = "bg-[#FF5A5F] text-white font-medium";
            } else if (isBetween) {
                bgClass = "bg-[#FF5A5F]/10 text-[#FF5A5F] font-medium";
            }

            btn.className = "calendar-day w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center text-sm transition-all relative overflow-hidden " + bgClass;
            btn.textContent = i;
            btn.disabled = isPast || isBlocked;
            
            // Accessible label for screen readers
            if (isBlocked) {
                btn.setAttribute('title', 'Already Booked');
                btn.setAttribute('aria-label', `${i} ${monthNames[lang][month]}, Booked`);
            }

            if (!isPast && !isBlocked) {
                btn.addEventListener('click', () => {
                    if (!checkIn || (checkIn && checkOut)) {
                        checkIn = thisDate;
                        checkOut = null;
                    } else if (thisDate > checkIn) {
                        // Secure dates allocation: prevent selecting ranges spanning across existing bookings
                        let hasOverlap = false;
                        let d = new Date(checkIn);
                        d.setDate(d.getDate() + 1);
                        while (d < thisDate) {
                            if (blockedDates.includes(formatDate(d))) {
                                hasOverlap = true;
                                break;
                            }
                            d.setDate(d.getDate() + 1);
                        }

                        if (hasOverlap) {
                            checkIn = thisDate;
                            checkOut = null;
                        } else {
                            checkOut = thisDate;
                        }
                    } else {
                        checkIn = thisDate;
                    }
                    renderCalendar();
                    updateBookingDisplay();
                });
            }

            grid.appendChild(btn);
        }
    }

    function formatDate(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function displayDate(d) {
        if (!d) return dpIn.getAttribute('data-text-add-date') || 'Add date';
        const options = { month: 'short', day: 'numeric', year: 'numeric' };
        const locMap = { 'es': 'es-ES', 'fr': 'fr-FR', 'it': 'it-IT', 'de': 'de-DE', 'ja': 'ja-JP' };
        return d.toLocaleDateString(locMap[lang] || 'en-US', options);
    }

    let pricesData = [];
    let exchangeRates = null;

    // Fetch build-time compiled seasonal prices json
    async function fetchPrices() {
        try {
            const response = await fetch('/cache/prices.json');
            if (response.ok) {
                pricesData = await response.json();
                if (checkIn && checkOut) {
                    updateBookingDisplay();
                }
            }
        } catch (err) {
            console.error('Error fetching seasonal prices:', err);
        }
    }

    // Fetch COP exchange rates with 1-hour client-side caching
    async function fetchExchangeRates() {
        try {
            const cachedRates = localStorage.getItem('cop_exchange_rates');
            const cachedTime = localStorage.getItem('cop_exchange_rates_time');
            
            if (cachedRates && cachedTime && (Date.now() - parseInt(cachedTime, 10) < 3600000)) {
                exchangeRates = JSON.parse(cachedRates);
                return;
            }
            
            const response = await fetch('https://open.er-api.com/v6/latest/COP');
            if (response.ok) {
                const data = await response.json();
                if (data && data.rates) {
                    exchangeRates = data.rates;
                    localStorage.setItem('cop_exchange_rates', JSON.stringify(data.rates));
                    localStorage.setItem('cop_exchange_rates_time', Date.now().toString());
                    if (checkIn && checkOut) {
                        updateBookingDisplay();
                    }
                }
            }
        } catch (err) {
            console.error('Error fetching currency exchange rates:', err);
        }
    }

    fetchPrices();
    fetchExchangeRates();

    // Captcha Logic for Direct Booking Form
    let bookingCaptchaSignature = '';
    const bookingCaptchaLabel = document.getElementById('booking-captcha-label');
    const bookingCaptchaChallenge = document.getElementById('booking-captcha-challenge');
    const bookingCaptchaResponse = document.getElementById('booking-captcha-response');

    async function loadBookingCaptcha() {
        if (!bookingCaptchaLabel) return;
        try {
            const response = await fetch('/api/book-request.php?action=captcha');
            if (response.ok) {
                const data = await response.json();
                const originalText = bookingCaptchaLabel.getAttribute('data-original') || bookingCaptchaLabel.textContent;
                if (!bookingCaptchaLabel.getAttribute('data-original')) {
                    bookingCaptchaLabel.setAttribute('data-original', originalText);
                }
                bookingCaptchaLabel.textContent = `${originalText} (${data.challenge})`;
                bookingCaptchaChallenge.value = data.challenge;
                bookingCaptchaSignature = data.signature;
                bookingCaptchaResponse.value = '';
            }
        } catch (err) {
            console.error('Error loading booking captcha:', err);
        }
    }

    if (bookingCaptchaLabel) {
        loadBookingCaptcha();
    }

    function calculateStayDetails(start, end) {
        let current = new Date(start);
        const endLimit = new Date(end);
        let nights = 0;
        let accommodationTotal = 0;

        while (current < endLimit) {
            nights++;
            const dateStr = formatDate(current);
            const tier = pricesData.find(p => p.property_id === propertyId && dateStr >= p.start_date && dateStr <= p.end_date);
            const rate = tier ? parseFloat(tier.nightly_rate_cop) : (propertyId === '1707' ? 450000 : 350000);
            accommodationTotal += rate;
            current.setDate(current.getDate() + 1);
        }

        return { nights, accommodationTotal };
    }

    function updateBookingDisplay() {
        const isSlate = !window.location.pathname.includes('1606');
        const textClass = isSlate ? "text-slate-900" : "text-stone-900";
        const muteClass = isSlate ? "text-slate-300" : "text-stone-300";

        dpIn.textContent = displayDate(checkIn);
        dpIn.className = "font-semibold text-lg " + (checkIn ? textClass : muteClass);
        
        dpOut.textContent = displayDate(checkOut);
        dpOut.className = "font-semibold text-lg " + (checkOut ? textClass : muteClass);

        const breakdownCard = document.getElementById('price-breakdown-card');
        const directForm = document.getElementById('direct-booking-form');

        if (checkIn && checkOut) {
            btnBook.href = airbnbUrl + "?check_in=" + formatDate(checkIn) + "&check_out=" + formatDate(checkOut);
            btnBookText.textContent = txtReady;

            // Compute direct booking subtotals
            const details = calculateStayDetails(checkIn, checkOut);
            const cleaningFee = propertyId === '1707' ? 100000 : 80000;
            const resortFee = 20000;
            const totalCOP = details.accommodationTotal + cleaningFee + resortFee;

            // Form bindings
            const formCheckIn = document.getElementById('form-check-in-date');
            const formCheckOut = document.getElementById('form-check-out-date');
            const formTotalPrice = document.getElementById('form-total-price-cop');
            if (formCheckIn) formCheckIn.value = formatDate(checkIn);
            if (formCheckOut) formCheckOut.value = formatDate(checkOut);
            if (formTotalPrice) formTotalPrice.value = totalCOP;

            // Populate Breakdown UI
            const accommodationValue = document.getElementById('rate-breakdown-value');
            const cleaningValue = document.getElementById('cleaning-fee-value');
            const resortValue = document.getElementById('resort-fee-value');
            const totalValue = document.getElementById('total-cop-value');

            const copFormatter = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 });

            if (accommodationValue) {
                const nightsFormat = accommodationValue.getAttribute('data-nights-format') || '({nights} nights)';
                accommodationValue.textContent = copFormatter.format(details.accommodationTotal) + ' ' + nightsFormat.replace('{nights}', details.nights);
            }
            if (cleaningValue) cleaningValue.textContent = copFormatter.format(cleaningFee);
            if (resortValue) resortValue.textContent = copFormatter.format(resortFee);
            if (totalValue) totalValue.textContent = copFormatter.format(totalCOP) + ' COP';

            // Convert to selected language's suggested currency
            const currencyBox = document.getElementById('converted-currency-box');
            const currencyValue = document.getElementById('converted-currency-value');
            
            if (currencyBox && currencyValue && exchangeRates) {
                const langToCurrency = { 'en': 'USD', 'es': 'USD', 'fr': 'EUR', 'de': 'EUR', 'it': 'EUR', 'ja': 'JPY' };
                const targetCurrency = langToCurrency[lang] || 'USD';
                const rate = exchangeRates[targetCurrency];
                
                if (rate) {
                    const converted = totalCOP * rate;
                    let formatted = '';
                    if (targetCurrency === 'JPY') {
                        formatted = new Intl.NumberFormat('ja-JP', { style: 'currency', currency: 'JPY' }).format(converted);
                    } else if (targetCurrency === 'EUR') {
                        formatted = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(converted);
                    } else {
                        formatted = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(converted);
                    }
                    currencyValue.textContent = formatted + ' ' + targetCurrency;
                    currencyBox.classList.remove('hidden');
                } else {
                    currencyBox.classList.add('hidden');
                }
            }

            // Reveal breakdown and direct booking form
            if (breakdownCard) breakdownCard.classList.remove('hidden');
            if (directForm) directForm.classList.remove('hidden');
        } else {
            btnBook.href = airbnbUrl;
            btnBookText.textContent = txtDefault;

            if (breakdownCard) breakdownCard.classList.add('hidden');
            if (directForm) directForm.classList.add('hidden');
        }
    }

    // Direct Booking Form AJAX submit logic
    const directFormElement = document.getElementById('direct-booking-form');
    if (directFormElement) {
        directFormElement.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btnSubmit = document.getElementById('btn-direct-submit');
            const btnSubmitText = document.getElementById('btn-direct-submit-text');
            const msgBox = document.getElementById('booking-form-message');

            const name = document.getElementById('booking-guest-name').value.trim();
            const email = document.getElementById('booking-guest-email').value.trim();
            const phone = document.getElementById('booking-guest-phone').value.trim();
            const captchaVal = bookingCaptchaResponse.value.trim();

            if (!name || !email || !phone || !captchaVal || !checkIn || !checkOut) {
                if (msgBox) {
                    msgBox.textContent = "Please fill in all required fields.";
                    msgBox.className = "p-3 rounded-xl text-sm font-semibold mb-4 bg-red-50 text-red-800 border border-red-200 block";
                }
                return;
            }

            if (btnSubmit) btnSubmit.disabled = true;
            if (btnSubmitText) btnSubmitText.textContent = "Sending...";

            try {
                const formData = new FormData(directFormElement);
                formData.append('captcha_signature', bookingCaptchaSignature);
                formData.append('captcha_response', captchaVal);

                const response = await fetch('/api/book-request.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    if (msgBox) {
                        msgBox.innerHTML = result.message || "Reservation inquiry sent successfully!";
                        msgBox.className = "p-4 rounded-xl text-sm font-semibold mb-4 bg-emerald-50 text-emerald-800 border border-emerald-200 block leading-relaxed";
                    }
                    directFormElement.reset();
                    if (btnSubmit) btnSubmit.style.display = 'none';

                    // Track Successful Direct Booking Conversion (Goal 5)
                    if (window._paq) {
                        window._paq.push(['trackEvent', 'Booking', 'Direct Booking Inquiry Success', propertyId]);
                        window._paq.push(['trackGoal', 5]);
                    }

                    // Seamless Checkout Redirection to MercadoPago Gateway
                    if (result.redirect_url) {
                        if (msgBox) {
                            msgBox.innerHTML += `<div class="mt-3 flex items-center text-emerald-900 text-xs font-semibold animate-pulse">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-emerald-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Redirecting to secure payment checkout...
                            </div>`;
                        }
                        setTimeout(() => {
                            window.location.href = result.redirect_url;
                        }, 1500);
                    }
                } else {
                    if (msgBox) {
                        msgBox.textContent = result.error || "An error occurred. Please try again.";
                        msgBox.className = "p-3 rounded-xl text-sm font-semibold mb-4 bg-red-50 text-red-800 border border-red-200 block";
                    }
                    if (btnSubmit) btnSubmit.disabled = false;
                    if (btnSubmitText) btnSubmitText.textContent = "Enviar Solicitud / Send Inquiry";
                    loadBookingCaptcha(); // Reset captcha on failure
                }
            } catch (err) {
                console.error('Error submitting direct booking inquiry:', err);
                if (msgBox) {
                    msgBox.textContent = "Network error. Please verify connection and try again.";
                    msgBox.className = "p-3 rounded-xl text-sm font-semibold mb-4 bg-red-50 text-red-800 border border-red-200 block";
                }
                if (btnSubmit) btnSubmit.disabled = false;
                if (btnSubmitText) btnSubmitText.textContent = "Enviar Solicitud / Send Inquiry";
            }
        });
    }

    btnPrev.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    btnNext.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

    // Matomo Tracking for Airbnb booking redirection (Goal 3)
    if (btnBook) {
        btnBook.addEventListener('click', () => {
            if (window._paq) {
                const propId = window.location.pathname.includes('1606') ? '1606' : '1707';
                window._paq.push(['trackEvent', 'Booking', 'Redirect to Airbnb', propId]);
                window._paq.push(['trackGoal', 3]);
            }
        });
    }

    // Matomo Tracking for WhatsApp direct booking click (Goal 4)
    const btnWhatsapp = document.getElementById('whatsapp-booking-link');
    if (btnWhatsapp) {
        btnWhatsapp.addEventListener('click', () => {
            if (window._paq) {
                const propId = window.location.pathname.includes('1606') ? '1606' : '1707';
                window._paq.push(['trackEvent', 'Contact', 'WhatsApp Booking Click', propId]);
                window._paq.push(['trackGoal', 4]);
            }
        });
    }

    updateBookingDisplay();
    renderCalendar();
    fetchBlockedDates(); // Fetch live availability on load
});

// Contact Form Logic
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('contact-form');
    if (!form) return;

    // Matomo Tracking for WhatsApp Sidebar Link (Goal 4)
    const whatsappContactLink = document.getElementById('whatsapp-contact-link');
    if (whatsappContactLink) {
        whatsappContactLink.addEventListener('click', () => {
            if (window._paq) {
                window._paq.push(['trackEvent', 'Contact', 'WhatsApp Sidebar Click', 'Contact Page']);
                window._paq.push(['trackGoal', 4]);
            }
        });
    }

    const btnSubmit = document.getElementById('submit-button');
    const txtSubmit = document.getElementById('submit-text');
    const msgBox = document.getElementById('form-message');
    const checkInInput = document.getElementById('check-in');
    const checkOutInput = document.getElementById('check-out');
    const captchaLabel = document.getElementById('captcha-label');
    const captchaChallenge = document.getElementById('captcha-challenge');
    const captchaResponse = document.getElementById('captcha-response');

    // Retrieve localized messages from form data attributes
    const msgSuccess = form.getAttribute('data-msg-success') || 'Thank you! Your message has been sent successfully.';
    const msgError = form.getAttribute('data-msg-error') || 'Something went wrong. Please check the fields and try again.';
    const msgSubmitting = form.getAttribute('data-msg-submitting') || 'Sending...';
    const msgDefaultSubmit = form.getAttribute('data-msg-submit') || 'Send Inquiry';
    const msgDateError = form.getAttribute('data-msg-date-error') || 'Check-out date must be after check-in date.';

    // Check URL query parameters for success/error redirect state (traditional post fallback)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === '1') {
        showMsg(msgSuccess, true);
    } else if (urlParams.has('error')) {
        const errorVal = urlParams.get('error');
        showMsg(errorVal ? decodeURIComponent(errorVal) : msgError, false);
    }

    // Setup Date picker min constraints
    const today = new Date().toISOString().split('T')[0];
    if (checkInInput) {
        checkInInput.min = today;
        checkInInput.addEventListener('change', () => {
            if (checkInInput.value) {
                checkOutInput.min = checkInInput.value;
                if (checkOutInput.value && checkOutInput.value <= checkInInput.value) {
                    const checkInDate = new Date(checkInInput.value);
                    checkInDate.setDate(checkInDate.getDate() + 1);
                    checkOutInput.value = checkInDate.toISOString().split('T')[0];
                }
            } else {
                checkOutInput.min = today;
            }
        });
    }
    if (checkOutInput) {
        checkOutInput.min = today;
    }

    // Dynamic Math Captcha Fetch
    let captchaSignature = '';
    async function loadCaptcha() {
        try {
            const actionPath = form.getAttribute('action') || 'api/contact-processor.php';
            const processorBase = actionPath.replace('contact-processor.php', '');
            
            const response = await fetch(processorBase + 'contact-processor.php?action=captcha');
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

    // Form Submit Handler
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        // Clear messages
        msgBox.className = 'hidden';
        msgBox.textContent = '';

        // Front-end Validation
        const nameVal = document.getElementById('name').value.trim();
        const emailVal = document.getElementById('email').value.trim();
        const phoneVal = document.getElementById('phone-number').value.trim();
        const messageVal = document.getElementById('message').value.trim();
        const captchaVal = captchaResponse.value.trim();

        if (!nameVal || !emailVal || !phoneVal || !messageVal || !captchaVal) {
            showMsg(msgError, false);
            return;
        }

        // Date Validation
        if (checkInInput && checkOutInput && checkInInput.value && checkOutInput.value) {
            if (new Date(checkInInput.value) >= new Date(checkOutInput.value)) {
                showMsg(msgDateError, false);
                if (window._paq) {
                    window._paq.push(['trackEvent', 'Contact Form', 'Validation Error', 'Check-out date before check-in']);
                }
                return;
            }
        }

        // Prepare Form Data
        const formData = new FormData(form);
        formData.append('captcha_signature', captchaSignature);

        // Submitting State
        btnSubmit.disabled = true;
        txtSubmit.textContent = msgSubmitting;
        btnSubmit.classList.add('opacity-75', 'cursor-not-allowed');

        try {
            const actionUrl = form.getAttribute('action') || 'api/contact-processor.php';
            const response = await fetch(actionUrl, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (response.ok && result.success) {
                showMsg(msgSuccess, true);

                // Matomo Goal & Event Tracking
                if (window._paq) {
                    const hasDates = checkInInput && checkOutInput && checkInInput.value && checkOutInput.value;
                    if (hasDates) {
                        // Goal 2: Booking Form Submitted
                        window._paq.push(['trackEvent', 'Contact Form', 'Booking Inquiry Success', `${checkInInput.value} to ${checkOutInput.value}`]);
                        window._paq.push(['trackGoal', 2]);
                    } else {
                        // Goal 1: Contact Form Inquiry
                        window._paq.push(['trackEvent', 'Contact Form', 'Contact Inquiry Success']);
                        window._paq.push(['trackGoal', 1]);
                    }
                }

                form.reset();
                loadCaptcha();
            } else {
                const errorMsg = result.message || msgError;
                showMsg(errorMsg, false);

                // Matomo Event Tracking for submission failure
                if (window._paq) {
                    window._paq.push(['trackEvent', 'Contact Form', 'Submission Failure', errorMsg]);
                }

                loadCaptcha();
            }
        } catch (err) {
            showMsg(msgError, false);

            // Matomo Event Tracking for network or script error
            if (window._paq) {
                window._paq.push(['trackEvent', 'Contact Form', 'Submission Error', err.message || 'Network Error']);
            }

            loadCaptcha();
        } finally {
            btnSubmit.disabled = false;
            txtSubmit.textContent = msgDefaultSubmit;
            btnSubmit.classList.remove('opacity-75', 'cursor-not-allowed');
        }
    });

    function showMsg(message, isSuccess) {
        msgBox.textContent = message;
        msgBox.className = isSuccess 
            ? 'p-4 rounded-2xl text-sm font-medium mb-6 bg-emerald-50 text-emerald-800 border border-emerald-100' 
            : 'p-4 rounded-2xl text-sm font-medium mb-6 bg-rose-50 text-rose-800 border border-rose-100';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});

// Property Image Gallery Lightbox Interactivity
document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.getElementById('lightbox-dialog');
    if (!dialog) return; // Exit if current page has no gallery

    const triggers = document.querySelectorAll('.gallery-trigger');
    const btnViewAll = document.getElementById('btn-view-all-photos');
    const imgActive = document.getElementById('lightbox-active-img');
    const txtCounter = document.getElementById('lightbox-counter');
    const txtCaption = document.getElementById('lightbox-caption');
    const btnClose = document.getElementById('lightbox-close');
    const btnPrev = document.getElementById('lightbox-prev');
    const btnNext = document.getElementById('lightbox-next');
    const announcer = document.getElementById('lightbox-announcer');

    let currentIndex = 0;
    let lastFocusedElement = null;
    const imagesData = [];

    // Parse image configurations from HTML attributes
    triggers.forEach(trigger => {
        const index = parseInt(trigger.getAttribute('data-index'), 10);
        if (isNaN(index)) return;
        imagesData[index] = {
            src: trigger.getAttribute('data-src'),
            alt: trigger.getAttribute('data-alt') || '',
            caption: trigger.getAttribute('data-caption') || ''
        };
    });

    // Remove any empty slots from the parsed images
    const cleanImages = imagesData.filter(item => item !== undefined);

    function showImage(index) {
        if (cleanImages.length === 0) return;

        // Wrap around circular navigation
        if (index < 0) {
            currentIndex = cleanImages.length - 1;
        } else if (index >= cleanImages.length) {
            currentIndex = 0;
        } else {
            currentIndex = index;
        }

        const activeImg = cleanImages[currentIndex];

        // Soft visual fade cross-transition
        imgActive.classList.remove('opacity-100', 'scale-100');
        imgActive.classList.add('opacity-0', 'scale-95');

        // Wait brief transition tick to swap resources to avoid flash
        setTimeout(() => {
            imgActive.src = activeImg.src;
            imgActive.alt = activeImg.alt;
            txtCaption.textContent = activeImg.caption;
            txtCounter.textContent = `${currentIndex + 1} / ${cleanImages.length}`;

            imgActive.onload = () => {
                imgActive.classList.remove('opacity-0', 'scale-95');
                imgActive.classList.add('opacity-100', 'scale-100');
            };

            // Accessibility: Update screen-reader polite status announcer
            if (announcer) {
                announcer.textContent = `Showing image ${currentIndex + 1} of ${cleanImages.length}: ${activeImg.caption}`;
            }
        }, 150);
    }

    function openLightbox(index) {
        lastFocusedElement = document.activeElement;
        
        // Open natively
        dialog.showModal();

        // Reveal with fade-in transition
        dialog.classList.remove('opacity-0', 'pointer-events-none');
        dialog.classList.add('opacity-100', 'pointer-events-auto');

        // Prevent body backdrop scrolling
        document.body.style.overflow = 'hidden';

        showImage(index);

        // Position initial focus on Close button
        if (btnClose) {
            setTimeout(() => btnClose.focus(), 50);
        }
    }

    function closeLightbox() {
        // Hide with fade transition
        dialog.classList.remove('opacity-100', 'pointer-events-auto');
        dialog.classList.add('opacity-0', 'pointer-events-none');

        // Wait transition duration before closing natively
        setTimeout(() => {
            dialog.close();
            document.body.style.overflow = '';
            
            // Restore accessibility focus to triggering element
            if (lastFocusedElement) {
                lastFocusedElement.focus();
            }
        }, 300);
    }

    // Set up click handlers for triggers
    triggers.forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.preventDefault();
            const index = parseInt(trigger.getAttribute('data-index'), 10);
            openLightbox(isNaN(index) ? 0 : index);
        });
    });

    if (btnViewAll) {
        btnViewAll.addEventListener('click', (e) => {
            e.preventDefault();
            openLightbox(0);
        });
    }

    if (btnClose) btnClose.addEventListener('click', closeLightbox);
    if (btnPrev) btnPrev.addEventListener('click', () => showImage(currentIndex - 1));
    if (btnNext) btnNext.addEventListener('click', () => showImage(currentIndex + 1));

    // Native Dialog Cancel/Escape Event Hook
    dialog.addEventListener('cancel', (e) => {
        e.preventDefault(); // Override immediate close to play transition
        closeLightbox();
    });

    // Light-Dismiss Backdrop Fallback (for older Safari)
    if (!('closedBy' in HTMLDialogElement.prototype)) {
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;

            const rect = dialog.getBoundingClientRect();
            const isDialogContent = (
                rect.top <= event.clientY &&
                event.clientY <= rect.top + rect.height &&
                rect.left <= event.clientX &&
                event.clientX <= rect.left + rect.width
            );

            if (!isDialogContent) {
                closeLightbox();
            }
        });
    }

    // Keyboard Navigation Arrow Handlers
    dialog.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') {
            e.preventDefault();
            showImage(currentIndex + 1);
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            showImage(currentIndex - 1);
        }
    });

    // Keyboard Accessibility Focus Trap cycling
    dialog.addEventListener('keydown', (e) => {
        if (e.key === 'Tab') {
            const focusables = dialog.querySelectorAll('button:not([disabled])');
            if (focusables.length === 0) return;

            const first = focusables[0];
            const last = focusables[focusables.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    last.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === last) {
                    first.focus();
                    e.preventDefault();
                }
            }
        }
    });

    // Mobile Swipe Gesture Event Hooks
    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;

    dialog.addEventListener('touchstart', (e) => {
        touchStartX = e.changedTouches[0].screenX;
        touchStartY = e.changedTouches[0].screenY;
    }, { passive: true });

    dialog.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].screenX;
        touchEndY = e.changedTouches[0].screenY;
        handleSwipe();
    }, { passive: true });

    function handleSwipe() {
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;

        // Verify it is a valid horizontal swipe gesture
        if (Math.abs(deltaX) > 50 && Math.abs(deltaY) < 40) {
            if (deltaX < 0) {
                showImage(currentIndex + 1); // Swiped Left -> Next
            } else {
                showImage(currentIndex - 1); // Swiped Right -> Prev
            }
        }
    }
});

