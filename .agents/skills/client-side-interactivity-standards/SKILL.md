---
name: client-side-interactivity-standards
description: Best practices for implementing lightweight, high-performance vanilla JS interactivity and multilingual integration without React hydration.
---

# Client-Side Interactivity Standards (No-Hydration Vanilla JS)

You are an expert frontend and interaction performance engineer. This guide defines strict guidelines for modifying, creating, and debugging client-side scripts under the `public/js/` directory.

---

## 🚀 Core Philosophy: Zero-Hydration Runtime

To maintain maximum loading speeds, perfect Core Web Vitals (LCP, INP), and total framework independence, **React is never hydrated on the client**.
*   **Compilation**: React exists exclusively to generate beautiful, static semantic HTML markup at build time.
*   **Interactivity**: Lightweight, native Vanilla JavaScript files inside [`public/js/`](public/js/) are linked at the bottom of pages to attach listeners and manage dynamic states.

---

## 🎨 Layout and Interactivity Coupling

When building interactive UI widgets (modals, calendars, galleries, form alerts, accordion cards):
1.  **Draft HTML Structure**: Implement structural tags, styling selectors, and baseline containers inside the React page components ([`src/pages/`](src/pages/)).
2.  **Bind Hooks**: Affix unique `id` handles or `data-*` attributes to target interactive wrappers.
3.  **Write Logic**: Program custom EventListeners, network fetch calls, and transition animations directly in matching script targets under `public/js/`.

---

## 🌐 Dynamic Localizations & Hydration Seam (SSG-to-Client Config)

Because script files are shared globally across different localized HTML files, **never hardcode text strings or duplicate inline translation dictionaries in JS, nor scrape ad-hoc `data-msg-*` attributes**.

### ❌ The Anti-Patterns:
```javascript
// Anti-Pattern 1: Leaking English literals
alert("Please select dates first.");

// Anti-Pattern 2: Scraping DOM attributes
const msg = form.getAttribute('data-msg-success');

// Anti-Pattern 3: Duplicate inline dictionaries inside scripts
const introTemplates = { en: "Welcome {guestName}", es: "Bienvenido {guestName}" };
```

### ✅ The Clean Standard:
Store all translations in [`src/i18n/dict.ts`](src/i18n/dict.ts), register needed page keys in [`src/config/pages.ts`](src/config/pages.ts), serialize them via `<script id="ovf-page-config" type="application/json">` in `src/templates/base.ts`, and access them through `window.t(key, params, fallback)` provided by [`public/js/page-config.js`](public/js/page-config.js):

```javascript
// inside public/js/main.js or any client script:
const pageConfig = (window.getPageConfig && window.getPageConfig()) || {};
const t = (key, params, fallback) => (window.t ? window.t(key, params, fallback) : (fallback !== undefined ? fallback : key));

// Translate with interpolation:
const welcomeMsg = t('guideWelcomeWithGuest', { guestName: 'John' });

// Authoritative endpoints and paths:
const apiEndpoint = `${pageConfig.apiBase || 'api/'}contact-processor.php`;
```

---

## ⚡ Performance: Async Script Lazy-Loading

Never burden core static page-load speeds with global, heavyweight third-party JavaScript scripts (e.g., payment portals, interactive maps, or heavy charts).

1.  **Define Asynchronous Loader**: Use programmatic promise loaders that construct script nodes dynamically:
    ```javascript
    function loadExternalSDK() {
        return new Promise((resolve, reject) => {
            if (window.SDKInstance) return resolve(window.SDKInstance);
            const script = document.createElement('script');
            script.src = "https://sdk.provider.com/v2.js";
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error("Failed to load SDK"));
            document.head.appendChild(script);
        });
    }
    ```
2.  **Attach to Event Hooks**: Trigger the loader dynamically on intent (e.g., when the calendar unhides, when input fields gain focus, or on first scroll) to eliminate block-blocking on FCP and LCP scores.
