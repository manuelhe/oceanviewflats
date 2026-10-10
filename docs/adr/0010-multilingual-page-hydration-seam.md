# Structured Build-Time Page Configuration and Client Hydration Seam

## Context

OceanViewFlats operates on a **zero-hydration runtime architecture**:
1. **Build-Time Compilation**: React components (`src/pages/`) and base templates (`src/templates/base.ts`) compile statically to semantic HTML across six supported languages (`en`, `es`, `fr`, `it`, `de`, `ja`) via `render.tsx`.
2. **Client-Side Runtime**: Lightweight Vanilla JavaScript scripts (`public/js/registry.js`, `public/js/guide.js`, `public/js/main.js`) load at the bottom of pages to attach event listeners and handle dynamic UI states without React client hydration.

To support dynamic client messages (e.g. form submission notices, asynchronous lookup alerts, credential gating), the project established the development rule:
> *Client-side Vanilla JS scripts must never embed hardcoded text literals since they process pre-compiled multilingual static HTML files. Extract all strings into `src/i18n/dict.ts` and bind them to DOM elements as `data-msg-*` attributes.*

### The Failure of the Shallow Attribute Seam

In practice, passing localized strings as scattered scalar `data-msg-*` attributes proved to be a **shallow, high-friction seam** that led to systemic leaks:
1. **Hardcoded Fallback Dictionaries in Client JavaScript**:
   Passing 10+ dynamic messages via HTML attributes on arbitrary elements created severe maintenance friction. To circumvent this, client scripts introduced embedded multi-language fallback dictionaries:
   - `public/js/registry.js` embedded hardcoded 6-language dictionaries for `errorMsgs`, `submittingMsgs`, `defaultDates`, and `defaultProperty`.
   - `public/js/guide.js` embedded a hardcoded 6-language `introTemplates` dictionary and brittle regex-stripping logic (`getGenericGreeting`) to manipulate strings dynamically.
2. **Translation Drift & Desynchronization**:
   Copy updates made in `src/i18n/dict.ts` did not propagate to the inline dictionaries in `public/js/`, resulting in translation drift and out-of-sync copy.
3. **English Fallback Leakage**:
   In `public/js/main.js`, every `getAttribute('data-msg-*')` call included hardcoded English string literals as fallback values. If an attribute was omitted in React JSX, non-English visitors received untranslated English text.
4. **Fragile Relative Path Inferences**:
   Client scripts lacked authoritative knowledge of page depth and API endpoints. `registry.js` munged `<form action>` attributes via `.replace('registry-processor.php', '')` to guess API base paths, while `guide.js` hardcoded `const pathPrefix = '../'`.

Under [ADR 0001](0001-mandatory-registry-before-access.md) and [ADR 0009](0009-concluded-reservation-credential-and-metadata-redaction.md), guest registration and guide credential gating are mission-critical hospitality workflows. A robust, deep seam is required to deliver 100% localized, synchronized interfaces without leaking English or duplicating copy.

## Decision

We establish an authoritative, deep **Build-Time Page Configuration and Hydration Seam** spanning the SSG compiler and client-side JavaScript.

### 1. Authoritative Configuration Seam (`#ovf-page-config`)

In `src/templates/base.ts`, every generated HTML page embeds a single structured, non-executable JSON script block before client script tags:

```html
<script id="ovf-page-config" type="application/json">
  {"lang":"es","assetPrefix":"../","apiBase":"../api/","i18n":{...}}
</script>
```

- **Non-Executable MIME Type**: `type="application/json"` ensures the script is treated purely as data by the browser, preventing arbitrary code execution and CSP eval warnings.
- **XSS & Script Breakout Escaping**: The serialized JSON payload is strictly escaped to neutralise script-tag terminations:
  ```typescript
  JSON.stringify(pageConfig).replace(/</g, '\\u003c')
  ```

### 2. Page-Scoped Localization Slices (`pageConfig.i18n`)

Serializing the entire `dict[lang]` (~21.6 KB uncompressed) into every static page would add unnecessary overhead. Instead:
- During SSG build (`render.tsx` / `src/config/pages.ts`), each page defines or extracts a **curated dictionary slice** containing only the keys relevant to that view's client interactivity.
- Curated slices keep the HTML payload overhead minimal (**1.2 – 1.8 KB** uncompressed, ~400 bytes gzipped), preserving 100 Core Web Vitals scores.

### 3. Authoritative Context Propagation (`lang`, `assetPrefix`, `apiBase`)

The page configuration payload authoritatively provides runtime environment coordinates calculated at build time:
- `lang`: Current document language code (`en`, `es`, `fr`, `it`, `de`, `ja`).
- `assetPrefix`: Relative path prefix for assets (e.g. `./` or `../`), ensuring subdir and preview portability.
- `apiBase`: Calculated relative API path (e.g. `api/` or `../api/`), eliminating form `action` string-munging.

### 4. Deep Client Interface (`window.getPageConfig()` & `window.t()`)

A tiny, zero-dependency helper (`public/js/page-config.js` or inline script) provides a minimal caller interface:

```javascript
// Retrieve structured configuration
const config = window.getPageConfig();

// Retrieve localized string with parameter interpolation
const label = window.t('registrySubmitting');
const welcome = window.t('guideWelcomeWithGuest', { guestName: 'Manuel' });
```

The implementation hides:
- DOM lookup and cached `JSON.parse` of `#ovf-page-config` with fallback to an empty object.
- Token replacement for `{key}` placeholders.
- Graceful fallback during incremental rollout.

### 5. Canonical Welcome Copy Separation

To eliminate fragile client-side string-stripping:
- `dict.ts` defines separate canonical keys:
  - `guideWelcomeWithGuest`: `"Welcome to your beachside home, {guestName}! ..."`
  - `guideWelcomeGeneric`: `"Welcome to your beachside home! ..."`
- `guide.js` selects the appropriate key based on whether a verified guest name is present, deleting the regex-stripping `getGenericGreeting` logic.

### 6. Contraction of Legacy `data-msg-*` Attributes

Through an expand-and-contract rollout:
1. `registry.js`, `guide.js`, and `main.js` migrate all text lookups to `window.t()`.
2. All hardcoded client dictionaries (`errorMsgs`, `submittingMsgs`, `defaultDates`, `defaultProperty`, `introTemplates`) and English fallback literals are deleted.
3. Scattered `data-msg-*` attributes are removed from React components (`Registry.tsx`, `Guide.tsx`, `BookingSection.tsx`, `Contact.tsx`).
4. Repository development rules in `AGENTS.md` and `tasks/lessons.md` are updated to enforce the `#ovf-page-config` + `window.t()` standard.

## Consequences

### Positive

- **100% Locality**: All copy across all 6 locales lives strictly in `src/i18n/dict.ts`. Copy updates immediately propagate to client scripts upon build without editing JavaScript.
- **Zero Hardcoded Text in JS**: Eliminates all embedded translation objects, language switches, and untranslated English fallbacks from `public/js/`.
- **Zero JSX Attribute Clutter**: React components no longer need 10+ scalar `data-msg-*` props on container elements.
- **Robust Parameter Interpolation**: Replaces ad-hoc string concatenation and regex trimming with uniform `{param}` token replacement.
- **Deterministic Path Resolution**: Injects authoritative `assetPrefix` and `apiBase`, eliminating fragile DOM attribute parsing.
- **Pristine Performance**: Scoped dictionary slices keep payload overhead to ~1.5 KB per page.

### Neutral / Trade-offs

- Requires pages with client-side interactivity to define their client dictionary slice in `src/config/pages.ts` or via a prefix extractor.
- Requires loading `page-config.js` before interactive page scripts.
