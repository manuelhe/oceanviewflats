# OceanViewFlats Architecture Overview

Welcome to the OceanViewFlats project! This document outlines the core architecture, build process, tech stack, and security layers to help guide future tasks.

## Tech Stack & Core Philosophy
This project is a hybrid **custom Static Site Generator (SSG)** combined with a lightweight **secure PHP REST API layer** optimized for absolute peak performance, SEO/GEO (Generative Engine Optimization), secure guest booking flows, and high availability.

*   **UI/Components:** React (with TypeScript), Lucide React (Icons).
*   **Styling:** Tailwind CSS v4.
*   **Build Pipeline:** `tsx` (TypeScript executor) + React `renderToStaticMarkup` + Vite (for Tailwind CSS compilation) + `terser` (for client JS minification).
*   **Interactivity:** Vanilla JavaScript (`public/js/main.js`, `public/js/registry.js`, `public/js/guide.js`, and `public/js/lang-detect.js`).
*   **Database:** MySQL (used for managing direct booking records, payment hold locks, and preferred language options).
*   **Payment Gateway:** MercadoPago (Option A checkout redirection flow, supported by a payment listener webhook).
*   **CI/CD:** GitHub Actions with automated SFTP deployment.

### Why Vanilla JS instead of React Hydration?
To achieve near-instant load times and perfect Lighthouse scores, the React components are only used to **generate static HTML**. They do *not* hydrate on the client. All client-side interactivity (navbar scroll effects, language dropdown toggle, interactive booking calendar, form validations, dynamic list additions, and clipboard copy states) is handled by lightweight, vanilla JavaScript located in `public/js/`. 
*   **Rule of Thumb:** If you need a new interactive element (like a modal, list, or a carousel), build the structural skeleton in the React component, add the necessary IDs or data-attributes, and write the interactive logic in a matching client file under `public/js/`.

---

## Directory Structure

```text
/
├── .agents/
│   ├── architecture.md     # This system architecture documentation
│   └── skills/             # Custom agent instructions and specialized tools
├── .github/
│   └── workflows/
│       └── deploy.yml      # CI/CD pipeline for automated SFTP deployments
├── public/                 # Static assets copied directly to the output 'dist' folder
│   ├── api/                # Hardened backend API endpoints and processor modules
│   │   ├── .htaccess       # Apache configuration restricting direct browse, CORS origins, and file access
│   │   ├── config.php      # Local environmental API variables (DB, Mail, Google Sheet, MercadoPago)
│   │   ├── translations.php # Unified API translation dictionary (EN, ES, FR, IT, DE, JA)
│   │   ├── utils.php       # Shared API Utility Library (CORS, Referer browser validations, math captchas)
│   │   ├── book-request.php # Secure direct booking hold engine and MySQL reservation logger
│   │   ├── contact-processor.php # Secure captcha-validated contact form processor
│   │   ├── registry-processor.php # Secure multi-guest registry processor and Sheets/local backups sync
│   │   ├── availability.php # Dynamic availability parser (Airbnb iCal proxy)
│   │   └── mercadopago-webhook.php # Gateway payment feedback listener and dynamic guest emails dispatcher
│   ├── data/
│   │   └── prices.csv      # Seasonal pricing dataset (night-by-night rates sheet)
│   ├── images/             # Optimized WebP imagery (grouped by flat prefix: 1707-, 1606-)
│   ├── js/                 # Client-side Vanilla JS
│   │   ├── main.js         # Core site interactivity (booking engine forms, exchange rates, validations)
│   │   ├── registry.js     # Guest registry multi-card client validator and submission handler
│   │   ├── guide.js        # Guest guide temporal code copier and registration routing linker
│   │   └── lang-detect.js  # Automatic browser language detection and redirection
│   ├── robots.txt          # Explicit crawler and bot permissions (Google, OpenAI, Claude, Perplexity)
│   └── llms.txt            # Markdown-formatted summary explicitly for AI LLM ingestion
├── src/
│   ├── components/         # Reusable React components (Navigation, Footer, Hero)
│   │   └── warm/           # Themed variations of components (for the romantic/family 1606 flat)
│   ├── config/
│   │   └── pages.ts        # The Central Router & Configuration. Defines paths, metadata, and JSON-LD schemas.
│   ├── constants/
│   │   └── config.ts       # Global constants (e.g., Airbnb URLs, Image paths)
│   ├── i18n/
│   │   └── dict.ts         # Central translation dictionary for all 6 supported languages
│   ├── pages/              # Top-level Page components (Home, Oceanview1707, Oceanview1606, Registry, Guide)
│   └── templates/
│       └── base.ts         # The master HTML document wrapper (`<!DOCTYPE html>...`)
├── scripts/
│   └── schema.sql          # Base database setup schema for remote installations
├── render.tsx              # The custom build script. It iterates over `pages.ts` and `LANGUAGES` to emit HTML.
├── vite.config.ts          # Vite configuration solely dedicated to building Tailwind v4 CSS.
└── package.json            # Contains the core `build` script (`tsx render.tsx && vite build && npx terser...`)
```

---

## The Build Process (`npm run build`)
The entire site is generated via a custom script located in `render.tsx` and chained npm commands. When you execute `npm run build`:
1.  **Clean & Scaffold:** The `dist/` directory is deleted and recreated.
2.  **Asset Copy:** Everything in `/public` is copied exactly as-is into `/dist`.
3.  **HTML Generation Loop:** `render.tsx` iterates through the `pages` array defined in `src/config/pages.ts`. For each page, it iterates through the 6 languages in `LANGUAGES`.
4.  **React Render:** It calls `renderToStaticMarkup(<PageComponent lang={lang} />)` to convert the React component tree into a raw HTML string.
5.  **Template Wrapping:** The raw HTML is injected into the `baseTemplate` (`src/templates/base.ts`), along with the computed asset prefixes, localized SEO metadata, Open Graph tags, canonical URLs, `hreflang` tags, and highly-detailed `JSON-LD` structured data.
6.  **Sitemap Generation:** A `sitemap.xml` is dynamically constructed and written to `/dist`.
7.  **CSS Compilation:** Vite compiles `src/style.css`, parsing the newly generated HTML files to extract the exact Tailwind utility classes used, and outputs the minified CSS to `dist/css/style.css`.
8.  **JS Minification:** Finally, `terser` runs on the JavaScript files in `public/js/` (`main.js`, `registry.js`, `guide.js`, and `lang-detect.js`), compressing and mangling them directly into the `dist/js/` folder to minimize payload sizes.

---

## Backend API & Security Architecture
All dynamic transactions are processed inside `/public/api/`. This folder has been hardened against common security threats:

### 1. The Shared API Utility Library (`utils.php`)
The backend is powered by a modular utility suite `utils.php` that implements core reusable subroutines:
*   **Database connection factory (`get_db_connection`)**: Standardizes PDO-based connections with strict error modes, utf8mb4 encoding, and disabled emulation prepares (blocking SQL Injections).
*   **Input sanitization (`clean_input`)**: Cleans and sanitizes strings using HTML entity conversions to prevent Cross-Site Scripting (XSS).
*   **CORS dispatcher (`enforce_security_headers_and_cors`)**: Limits CORS permissions explicitly to `"https://www.oceanviewflats.com"` and `"https://oceanviewflats.com"` (while allowing local environment fallbacks like `localhost` or `127.0.0.1` for development).
*   **Browser-check Referer Check (`enforce_referer_check`)**: Rejects requests missing headers when the client browser agents are traditional web agents (blocking direct browser bar scripts accesses).
*   **Signed Math CAPTCHAs (`generate_captcha_challenge` / `verify_captcha_challenge`)**: Employs secure HMAC-signed challenges preventing automated bot submissions without maintaining persistent PHP sessions.
*   **Rate-limiting engine (`enforce_rate_limit`)**: IP-based rate limiting with temporal JSON state tracking in `sys_get_temp_dir()`.
*   **Language whitelister (`get_validated_lang`)**: Sanitizes and strictly validates language inputs against our whitelisted supported array `['en', 'es', 'fr', 'it', 'de', 'ja']`, falling back to English on invalid or blank parameters.

### 2. Multi-Language Dictionary (`translations.php`)
Rather than maintaining local arrays inside every processor script, all back-end text outputs (receipt emails, validation messages, CAPTCHA errors, and rate limits) are loaded dynamically from `/public/api/translations.php` using the client's validated `lang` preference, eliminating redundant duplication and keeping localizations consistent.

### 3. Self-Healing Database Setup
Since the schema setup may exist across remote servers, schema migrations (such as adding new columns like `lang`) are programmed directly into the initialization block of `book-request.php` inside structured `try { $pdo->exec("ALTER TABLE ...") } catch (PDOException $e) {}` blocks. This ensures existing systems upgrade seamlessly upon deployment.

---

## Core Project Tenets

### 1. Multi-Language (i18n) by Default
Every text string visible to the user must be abstracted into `src/i18n/dict.ts` for the frontend and `public/api/translations.php` for the backend. Never hardcode text.
*   **Automatic Redirection:** The `public/js/lang-detect.js` script automatically redirects users from the English index to their browser's preferred language upon their first visit.
*   **Preference Persistence:** Once a user views a localized page or explicitly selects a language from the dropdown, their choice is saved to `localStorage` to prevent forceful redirecting.

### 2. Asset Prefixes for Nested Routes
Because the site generates nested routes (e.g., `dist/Oceanview1707/es.html`), absolute paths to local assets (like `/images/hero.webp` or `/js/main.js`) break when testing locally without a server. 
*   **The Fix:** `render.tsx` computes an `assetPrefix` (either `./` for root files or `../` for nested files). This prefix is passed down as a prop to `AppProps` and must be prepended to all local `<img>` sources and `<script>`/`<link>` tags.

### 3. SEO and GEO (Generative Engine Optimization) Focus
This site is heavily optimized for both Google and AI engines (ChatGPT, Perplexity).
*   **Semantic HTML:** Ensure strict heading hierarchy (`<h1>` down to `<h3>`). Use `<main>`, `<section>`, and explicit `<article>` wrappers.
*   **Structured Data:** Every page config (`src/config/pages.ts`) defines an explicit `getStructuredData` function. These schemas strictly adhere to Google Search Console requirements for the `VacationRental` type, meaning `containsPlace` must granularly define nested rooms, occupancy, features, and precise coordinates.
*   **Crawler Guides:** Both `public/robots.txt` (to explicitly allow major AI bots) and `public/llms.txt` (to feed structured markdown summaries to LLMs) must be manually updated if major architectural or property features change.