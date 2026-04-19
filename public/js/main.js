// Simple scroll effect for navbar
window.addEventListener('scroll', () => {
    const nav = document.getElementById('navbar');
    const logo = document.getElementById('nav-logo');
    const toggle = document.getElementById('lang-toggle');
    if (!nav || !logo || !toggle) return;
    
    if (window.scrollY > 50) {
        nav.classList.add('bg-white/90', 'backdrop-blur-md', 'shadow-sm', 'py-3');
        nav.classList.remove('bg-transparent', 'py-5');
        logo.classList.add('text-[#FF5A5F]');
        logo.classList.remove('text-white');
        toggle.classList.add('bg-slate-100', 'hover:bg-slate-200', 'text-slate-700');
        toggle.classList.remove('bg-white/20', 'hover:bg-white/30', 'text-white', 'backdrop-blur-sm');
    } else {
        nav.classList.remove('bg-white/90', 'backdrop-blur-md', 'shadow-sm', 'py-3');
        nav.classList.add('bg-transparent', 'py-5');
        logo.classList.remove('text-[#FF5A5F]');
        logo.classList.add('text-white');
        toggle.classList.remove('bg-slate-100', 'hover:bg-slate-200', 'text-slate-700');
        toggle.classList.add('bg-white/20', 'hover:bg-white/30', 'text-white', 'backdrop-blur-sm');
    }
});

// Calendar Logic
document.addEventListener('DOMContentLoaded', () => {
    const widget = document.getElementById('calendar-widget');
    if (!widget) return;

    let currentDate = new Date();
    let checkIn = null;
    let checkOut = null;
    
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
            const isPast = thisDate < today;
            const isCheckIn = checkIn && thisDate.getTime() === checkIn.getTime();
            const isCheckOut = checkOut && thisDate.getTime() === checkOut.getTime();
            const isBetween = checkIn && checkOut && thisDate > checkIn && thisDate < checkOut;

            let bgClass = "bg-white hover:bg-slate-100 text-slate-700";
            if (isPast) bgClass = "bg-transparent text-slate-300 cursor-not-allowed";
            if (isCheckIn || isCheckOut) bgClass = "bg-[#FF5A5F] text-white font-medium";
            if (isBetween) bgClass = "bg-[#FF5A5F]/10 text-[#FF5A5F] font-medium";

            btn.className = "calendar-day w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center text-sm transition-all " + bgClass;
            btn.textContent = i;
            btn.disabled = isPast;

            if (!isPast) {
                btn.addEventListener('click', () => {
                    if (!checkIn || (checkIn && checkOut)) {
                        checkIn = thisDate;
                        checkOut = null;
                    } else if (thisDate > checkIn) {
                        checkOut = thisDate;
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

    function updateBookingDisplay() {
        dpIn.textContent = displayDate(checkIn);
        dpIn.className = "font-semibold text-lg " + (checkIn ? "text-slate-900" : "text-slate-300");
        
        dpOut.textContent = displayDate(checkOut);
        dpOut.className = "font-semibold text-lg " + (checkOut ? "text-slate-900" : "text-slate-300");

        if (checkIn && checkOut) {
            btnBook.href = airbnbUrl + "?check_in=" + formatDate(checkIn) + "&check_out=" + formatDate(checkOut);
            btnBookText.textContent = txtReady;
        } else {
            btnBook.href = airbnbUrl;
            btnBookText.textContent = txtDefault;
        }
    }

    btnPrev.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    btnNext.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

    updateBookingDisplay();
    renderCalendar();
});

// Matomo
let _paq = window._paq = window._paq || [];
/* tracker methods like "setCustomDimension" should be called before "trackPageView" */
_paq.push(["setDoNotTrack", true]);
_paq.push(['trackPageView']);
_paq.push(['enableLinkTracking']);
(function() {
    let u="//stats.fractalserver.com/";
    _paq.push(['setTrackerUrl', u+'matomo.php']);
    _paq.push(['setSiteId', '2']);
    let d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
})();
// End Matomo Code
