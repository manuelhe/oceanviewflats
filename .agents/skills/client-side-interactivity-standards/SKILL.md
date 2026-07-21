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

## 🌐 Dynamic Localizations (No Orphan Literals)

Because script files are shared globally across different localized HTML files, **never hardcode text strings or message outputs directly in JS**.

### ❌ The Anti-Pattern:
```javascript
// This leaks English text to Spanish, French, and Japanese viewers
alert("Please select dates first.");
```

###  The Clean Standard:
Store all translations in [`src/i18n/dict.ts`](src/i18n/dict.ts) and attach them to DOM nodes as custom `data-msg-*` parameters:

```typescript
// inside React component:
<form 
  id="contact-form"
  data-msg-success={dict[lang].success_msg}
  data-msg-error={dict[lang].error_msg}
>
```

```javascript
// inside public/js/main.js:
const form = document.getElementById('contact-form');
const successMsg = form.getAttribute('data-msg-success');
const errorMsg = form.getAttribute('data-msg-error');
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
