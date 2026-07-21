# OceanViewFlats System Architecture

Welcome to the **OceanViewFlats** direct booking system. This document outlines the core architecture, static site compilation pipelines, secure PHP REST APIs, and database lifecycle configurations.

---

## 🏗️ Technical Architecture & Philosophy

OceanViewFlats is engineered as a high-performance **custom Static Site Generator (SSG)** paired with a secure, decoupled **PHP REST API backend**. It is optimized for near-instantaneous page-load speeds, high SEO ranking, robust security, and seamless direct-booking flows.

```text
                               +-----------------------------+
                               |     Static site (React)     |
                               +-----------------------------+
                                              |
                     1. User selects dates    | 2. Local validation request (Overlap check)
                     and clicks book          v
                               +-----------------------------+
                               | public/api/book-request.php |
                               +-----------------------------+
                                              |
                                              | 3. Returns reservation_uid & total_price
                                              v
                               +-----------------------------+
                               |    MP Payment Brick (UI)    |
                               | (Credit/Debit/PSE/Efecty)   |
                               +-----------------------------+
                                              |
                       4. Guest submits       | 5. Processed payload dispatch
                       payment inside iframe  v
                               +-----------------------------+
                               |   public/api/payment.php    |
                               +-----------------------------+
                                              |
                                              | 6. Server-to-server transaction call
                                              v
                               +-----------------------------+
                               |    MercadoPago REST API     |
                               +-----------------------------+
```

### Key Technical Pillars:
*   **Zero-Hydration React-to-HTML Compiler**: React components (with TypeScript) and Tailwind CSS are used exclusively at build time to pre-compile structural, SEO, and styling parameters.
*   **Framework-Free Native Interactivity**: There is no bulky React runtime running on the client. Interactivity (menus, calendars, pricing calculations, validations, and dynamic card registers) is managed via highly minified, lightweight native Vanilla JavaScript inside `public/js/`.
*   **Custom Inline Checkout (Bricks)**: Secure card tokenization, bank transfers (PSE), and voucher payments (Efecty) are rendered natively within an inline iframe and validated via a backend server-to-server API.
*   **Centralized CLI Migrations**: No dynamic SQL schema modification occurs inside transactional API scripts. Structural updates are handled by a dedicated CLI tool.

---

## 📁 Project Directory Layout

```text
/
├── .agents/
│   ├── architecture.md       # [This Document] High-level architectural specification
│   └── skills/               # Custom instructions for AI Developer agents
├── .github/
│   └── workflows/
│       └── deploy.yml        # CI pipeline injecting secrets and deploying via SFTP
├── public/                   # Public assets output exactly to 'dist' folder
│   ├── api/                  # Secure PHP REST endpoints
│   │   ├── .htaccess         # Hardened Apache headers, CORS whitelisting, and browse blocks
│   │   ├── config.php        # Central environment config loading
│   │   ├── translations.php  # Unified back-end multi-language dictionary (6 languages)
│   │   ├── utils.php         # Shared API utilities (sanitization, referers, Signed CAPTCHAs)
│   │   ├── book-request.php  # Validates room availability and locks pending reservation
│   │   ├── payment.php       # Processes inline payments server-to-server with MercadoPago
│   │   ├── mercadopago-webhook.php # Listener for offline payment statuses (PSE/Efecty)
│   │   ├── availability.php  # iCal Airbnb reservation block proxy
│   │   ├── contact-processor.php  # Captcha-validated contact handler
│   │   └── registry-processor.php # Captcha-validated guest register handler
│   ├── js/                   # Native Client-side JS scripts
│   │   ├── main.js           # Main interaction controller (Dynamic SDK lazy-loader, MP Brick handler)
│   │   ├── registry.js       # Dynamic multi-card guest validator & transmitter
│   │   ├── guide.js          # Guest dashboard copier & temporal routing handler
│   │   └── lang-detect.js    # Automatic locale detection & redirection engine
│   ├── robots.txt            # Explicit permissions mapping for AI crawlers & search bots
│   └── llms.txt              # Markdown outline explicitly compiled for LLM ingestions
├── src/
│   ├── components/           # Modular React layout blocks (Footer, Nav, Booking Form)
│   ├── config/
│   │   └── pages.ts          # Central router compiling slugs, SEO headers, & structured JSON-LD schemas
│   ├── constants/
│   │   └── config.ts         # Constant declarations (Airbnb targets, public MercadoPago tokens)
│   ├── i18n/
│   │   └── dict.ts           # Unified translation dictionaries mapping UI terms in 6 languages
│   ├── pages/                # Top-level Page components (Home, Oceanview1707, Oceanview1606, Registry)
│   └── templates/
│       └── base.ts           # Master HTML shell document definition
├── scripts/
│   ├── schema.sql            # Master MySQL relational schema
│   └── migrate.php           # Central CLI self-healing migration runner
├── render.tsx                # Dynamic Node pre-compiler converting React elements into HTML files
├── vite.config.ts            # Tailwind CSS compiler
└── package.json              # Development commands (build, minify, compile, post-processing)
```

---

## ⚙️ Compilation Build Cycle (`npm run build`)

Static page assets are generated and post-processed in an automated chained command loop:

```text
+------------------+     1. Clean dist/     +-----------------------------+
|    render.tsx    | ---------------------> | Compile prices.csv to JSON  |
+------------------+                        +-----------------------------+
         |
         | 2. Iterate pages.ts & languages
         v
+------------------+     3. Inject Shell    +-----------------------------+
| renderToStatic() | ---------------------> |   Write HTML to output paths |
+------------------+                        +-----------------------------+
         |
         | 4. Compile Tailwind CSS via Vite
         v
+------------------+     5. Minify Scripts  +-----------------------------+
|    vite build    | ---------------------> | Distribute dist/ assets      |
+------------------+                        +-----------------------------+
```

### Detailed Compile Sequence:
1.  **Clear Directory**: Wipes previous `dist/` directory artifacts.
2.  **Compile Cached Pricing Sheets**: Parses seasonal, night-by-night pricing models (`public/data/prices.csv`) into structured, minified static files (`dist/data/prices.json`).
3.  **Static HTML Generation Loop**:
    *   Iterates through routing parameters declared in `src/config/pages.ts`.
    *   For each route, the loop iterates across all 6 supported language locales (**en, es, fr, it, de, ja**).
    *   Computes depth-specific relative asset prefixes (e.g. `./` for root pages, or `../` for subdirectories like `/Oceanview1707/`).
    *   Executes `ReactDOMServer.renderToStaticMarkup` on page modules, wrapping output inside the master template (`src/templates/base.ts`) with custom canonical headers, alternates, and schema JSON-LD strings.
4.  **Tailwind CSS Bundle**: Vite parses generated static HTML and packages compiled, purged styles into `dist/css/style.css`.
5.  **Terser Minification**: Compresses and mangles raw client scripts from `public/js/` into `dist/js/`, decreasing payload weight by up to 60%.

---

## 🔒 Hardened API & Relational Schema Layer

All dynamic network traffic routes to standalone transaction endpoints under `/public/api/`, fortified against modern injection, spam, and DoS vectors.

### 1. Robust Middleware (`utils.php`)
*   **Database Connections**: Standardizes PDO handles with strict errors, UTF-8 character attributes, and disabled prepare emulation to prevent SQL injection.
*   **XSS Mitigation**: Cleans and validates parameters using native sanitizers.
*   **Strict Whitelisted CORS**: Rejects request origins not matching `"https://www.oceanviewflats.com"` (while maintaining localhost exceptions for local development pipelines).
*   **State-free signed CAPTCHAs**: Solves math challenges via signed HMAC tokens, stopping automated spam without holding heavy database session records.
*   **IP-Based Rate Limiting**: Throttles submissions using dynamic temporary JSON tracking tables.

### 2. Centralized Database Schema Migrations
*   No database creations or `ALTER TABLE` operations occur inside client transactional API endpoints.
*   Database updates are compiled under [`scripts/schema.sql`](file:///Users/manuel.herrera/Projects/17071606/scripts/schema.sql) and ran through the CLI-exclusive self-healing migrator [`scripts/migrate.php`](file:///Users/manuel.herrera/Projects/17071606/scripts/migrate.php).

### 3. Double-Charge Protection (Idempotency Key)
Payments utilize unique reservation identifier codes as idempotency keys. Pre-payment steps check the `payment_idempotency` table before routing to MercadoPago, short-circuiting duplicate transactions instantly.

---

## 🌐 Dynamic Localizations Bridge

We maintain clean separation between pre-compiled static structures and dynamic script notifications:
*   **Core Dictionary**: Standard UI terms are mapped inside the central React translation sheet ([`src/i18n/dict.ts`](file:///Users/manuel.herrera/Projects/17071606/src/i18n/dict.ts)).
*   **Bridging System**: React embeds translated terms inside custom parent HTML node attributes (e.g., `data-msg-success="Trans_Val"`).
*   **Client Parsing**: Native JS scripts query these attributes on load. This completely prevents hardcoded English terms from leaking onto Spanish, French, Italian, German, or Japanese viewports.
*   **API Translation Keys**: Dynamic server outputs are matched against the validated client request language query and loaded from the central backend dictionary [`public/api/translations.php`](file:///Users/manuel.herrera/Projects/17071606/public/api/translations.php).

---

## ⚡ Core Web Vitals (PageSpeed) Protection
*   **Zero Global Script Loading**: Third-party integrations (like MercadoPago's `sdk.mercadopago.com/js/v2`) are completely omitted from the global HTML base template.
*   **Asynchronous On-Demand Load**: A lightweight dynamic Promise loader is configured inside `main.js`. It fetches the script asynchronously *only* when the direct booking calendar form unhides, achieving perfect performance metrics on static text-heavy informational pages.