# OceanViewFlats System Architecture

Welcome to the **OceanViewFlats** direct booking and property management system. This document outlines the core architecture, static site compilation pipelines, secure PHP REST APIs, administrative portal, and database lifecycle configurations.

---

## 🏗️ Technical Architecture & Philosophy

OceanViewFlats is engineered as a high-performance, hybrid platform comprising three integrated layers:
1. **Public Direct-Booking Portal**: A custom zero-hydration **Static Site Generator (SSG)** paired with lightweight Vanilla JavaScript islands and a hardened PHP REST backend.
2. **Administrative Management Portal (PMS)**: A server-rendered PHP 8.3 application enhanced with **HTMX** and Tailwind CSS, hosted on an isolated subdomain (`admin.oceanviewflats.com`) using **cPanel Pattern A** topology.
3. **Shared Core Domain Layer**: Reusable, framework-agnostic domain models (`src/Domain/`) that enforce authoritative business rules for quote pricing, calendar availability, and guest access PIN generation.

```text
[Guest Facing: oceanviewflats.com]             [Admin Facing: admin.oceanviewflats.com]
         │                                                      │
         ▼                                                      ▼
+----------------------------------+          +----------------------------------+
|   Public SSG (React/TypeScript)  |          |   Admin UI (PHP 8.3 / HTMX)      |
|   Zero-hydration static HTML     |          |   Server-rendered components     |
|   Vanilla JS booking widgets     |          |   Subdomain-isolated sessions    |
+----------------------------------+          +----------------------------------+
         │                                                      │
         ├───────────────► /api/ REST Endpoints ◄───────────────┤
         │                 (public_html/api/)                   │
         │                                                      │
         ▼                                                      ▼
+--------------------------------------------------------------------------------+
|                        Shared Core Domain (src/Domain/)                        |
|   - QuoteEngine: Authoritative seasonal pricing, fees, and minimum stay rules  |
|   - ReservationLedger: Double-booking prevention & maintenance block tracking   |
|   - DoorCodeGenerator: Dynamic 7-digit PIN generation from primary guest ID   |
+--------------------------------------------------------------------------------+
                                         │
                                         ▼
+--------------------------------------------------------------------------------+
|                   MySQL Database (InnoDB / UTF-8 mb4)                          |
|   - reservations (booking ledger, registry status, dynamic door_code)          |
|   - guest_registries (statutory Colombian guest audit records)                 |
|   - calendar_blocks (internal maintenance holds)                               |
|   - payment_idempotency (double-charge short-circuit protection)               |
|   - admin_users (Argon2id credentials, roles, lockout tracking)                |
|   - admin_audit_logs (immutable administrative audit log)                      |
+--------------------------------------------------------------------------------+
```

### Key Technical Pillars:
*   **Zero-Hydration React-to-HTML Compiler**: React components (with TypeScript) and Tailwind CSS are used exclusively at build time to pre-compile structural, SEO, and styling parameters.
*   **Framework-Free Native Interactivity**: There is no bulky React runtime running on the client. Interactivity (menus, calendars, pricing calculations, validations, and dynamic card registers) is managed via highly minified, lightweight native Vanilla JavaScript inside `public/js/`.
*   **Subdomain-Isolated Administration (Pattern A)**: Property management workflows run on `admin.oceanviewflats.com`. Its DocumentRoot is strictly isolated at `/home/<user>/admin/public`, placing all sensitive PHP code, shared domain models, vendor libraries, and CLI tools outside the web root.
*   **Dual-Pipeline Deployment Architecture**: Decoupled CI/CD pipelines allow static marketing releases to deploy independently from backend administrative updates.
*   **Centralized CLI Migrations & Provisioning**: No dynamic SQL schema modification occurs inside transactional API scripts. Structural updates are handled by `scripts/migrate.php`, and administrator user provisioning is handled by `scripts/create-admin-user.php`.

---

## 📁 Project Directory Layout

```text
/
├── .agents/
│   ├── architecture.md       # [This Document] High-level architectural specification
│   └── skills/               # Custom instructions for AI Developer agents
├── .github/
│   └── workflows/
│       ├── deploy.yml        # CI/CD: Compiles SSG and deploys to public_html via SFTP
│       ├── admin-deploy.yml  # CI/CD: Deploys admin, domain, vendor, & scripts to /home/<user>/
│       └── admin-ci.yml      # CI: Validates admin PHP test suite & PHPStan Level 8
├── admin/                    # Administrative PMS Monorepo Subsystem
│   ├── public/               # DocumentRoot for admin.oceanviewflats.com (Pattern A)
│   │   ├── index.php         # Admin front-controller entry point
│   │   └── .htaccess         # Mod_rewrite clean URL routing & security headers
│   ├── src/                  # Admin controllers, middleware, and authentication logic
│   ├── templates/            # Server-rendered PHP templates & HTMX partials
│   ├── tests/                # Automated PHPUnit tests for administrative routes
│   └── phpstan.neon          # PHPStan static analysis configuration (Level 8)
├── public/                   # Public static assets & REST APIs (compiled to 'dist/')
│   ├── api/                  # Secure PHP REST endpoints
│   │   ├── .htaccess         # Hardened Apache headers, CORS whitelisting, browse blocks
│   │   ├── config.php        # Central environment config loading
│   │   ├── translations.php  # Unified back-end multi-language dictionary (6 languages)
│   │   ├── utils.php         # Shared API utilities (PDO, sanitization, rate limiting)
│   │   ├── book-request.php  # Validates room availability and locks pending reservation
│   │   ├── payment.php       # Processes inline payments server-to-server with MercadoPago
│   │   ├── guide-access.php  # Statutory gate releasing credentials only upon registry completion
│   │   ├── quote.php         # Authoritative runtime quote computation
│   │   └── registry-processor.php # Validates legal Colombian Guest Registry submission
│   ├── js/                   # Native Client-side JS scripts (main.js, registry.js, guide.js)
│   ├── robots.txt            # Search and AI crawler permissions
│   └── llms.txt              # Markdown outline explicitly compiled for LLM ingestions
├── src/
│   ├── Domain/               # Shared Domain Models (Single Source of Truth)
│   │   ├── Quote/            # SeasonalPricer, QuoteEngine, RateRules
│   │   ├── Reservation/      # ReservationLedger, MaintenanceBlock, Availability
│   │   └── Security/         # DoorCodeGenerator (7-digit PIN extraction)
│   ├── components/           # Modular React layout blocks (Footer, Nav, Booking Form)
│   ├── config/               # pages.ts (routes, SEO headers, JSON-LD schemas)
│   ├── constants/            # Constants (theme colors, Airbnb feed IDs, MP public keys)
│   ├── i18n/                 # dict.ts (UI translation dictionary across 6 languages)
│   ├── pages/                # Top-level Page components (Home, Oceanview1707, Oceanview1606)
│   └── templates/            # Master HTML shell document definition (base.ts)
├── scripts/
│   ├── schema.sql            # Master MySQL relational schema
│   ├── migrate.php           # Central CLI self-healing migration runner
│   ├── create-admin-user.php # CLI-exclusive administrator user provisioning utility
│   └── generate-docs.php     # OpenAPI documentation generator
├── composer.json             # PHP dependencies and PSR-4 autoload mapping
├── package.json              # Node.js build commands (SSG compilation, Terser, Vite)
└── render.tsx                # Dynamic Node pre-compiler converting React to HTML
```

---

## 🚀 Dual-Pipeline CI/CD Architecture

OceanViewFlats implements a **dual-pipeline deployment model** to optimize deployment velocity and security:

```text
                                [Developer Push to 'main']
                                             │
                       ┌─────────────────────┴─────────────────────┐
                       ▼                                           ▼
          Path matches public site?                    Path matches admin/domain?
                       │                                           │
                       ▼                                           ▼
      +---------------------------------+         +---------------------------------+
      |    .github/workflows/           |         |    .github/workflows/           |
      |    deploy.yml                   |         |    admin-deploy.yml             |
      +---------------------------------+         +---------------------------------+
                       │                                           │
       1. Node 24: npm ci                        1. PHP 8.3: composer install
       2. Inject public .htaccess secrets           (--no-dev --optimize-autoloader)
       3. Compile SSG: npm run build             2. Inject admin/public/.htaccess
       4. SFTP Deploy to:                        3. Stage: admin/, Domain/, vendor/, scripts/
          /home/<user>/public_html/              4. SFTP Deploy to:
             (Port 2223)                            /home/<user>/ (Port 2223)
                       │                                           │
                       ▼                                           ▼
         oceanviewflats.com                        admin.oceanviewflats.com
```

### 1. Public Static Site Pipeline (`deploy.yml`)
- **Execution Speed**: Rapid execution because it only compiles static assets without installing heavy PHP dev dependencies.
- **Payload**: Deploys `./dist/.` into `/home/<user>/public_html`.
- **Target**: Public marketing views (`https://oceanviewflats.com`) and public REST endpoints (`/api/*.php`).

### 2. Admin & Domain/Vendor Pipeline (`admin-deploy.yml`)
- **Selective Triggering**: Automatically executes when changes occur in `admin/**`, `src/Domain/**`, `scripts/**`, or `composer.*`.
- **Production Optimization**: Executes `composer install --no-dev --prefer-dist --optimize-autoloader`, packaging lean production dependencies.
- **Payload**: Deploys to `/home/<user>/`, updating:
  - `/home/<user>/admin/`: Admin front-controller and application code.
  - `/home/<user>/src/Domain/`: Canonical domain business logic.
  - `/home/<user>/vendor/`: Single source of truth Composer autoloader.
  - `/home/<user>/scripts/`: CLI operational utilities.

---

## 🌐 Hosting Topology: cPanel Pattern A

To protect administrative source code from accidental exposure over HTTP, the server uses **Pattern A**:

```text
/home/<user>/
├── public_html/              <-- DocumentRoot for oceanviewflats.com
└── admin/
    └── public/               <-- DocumentRoot for admin.oceanviewflats.com
```

### Advantages of Pattern A:
1. **Physical Webroot Isolation**: Application controllers (`admin/src/`), templates (`admin/templates/`), domain models (`src/Domain/`), Composer dependencies (`vendor/`), and migration scripts (`scripts/`) are located in the user's home folder completely outside any web-accessible DocumentRoot.
2. **Failure Resistance**: If an Apache `.htaccess` rule fails or mod_rewrite is misconfigured, raw PHP source code or sensitive configuration cannot be accessed or indexed over HTTP.
3. **Shared Runtime Autoloader**: Both `public_html/api/*.php` and `admin/public/index.php` share the production autoloader at `/home/<user>/vendor/autoload.php`:
   - `public_html/api/*.php` calls `require_once dirname(__DIR__, 2) . '/vendor/autoload.php';`
   - `admin/public/index.php` calls `require_once dirname(__DIR__, 2) . '/vendor/autoload.php';`
4. **Resilient Configuration (`ConfigPathResolver`)**: The admin application and CLI migration runner locate database credentials by sequentially checking `public/api/config.php` and `public_html/api/config.php`.

---

## 🔒 Hardened API, Authentication, & Relational Schema Layer

### 1. Robust Public API Middleware (`utils.php`)
*   **Database Connections**: Standardizes PDO handles with strict errors, UTF-8 character attributes, and disabled prepare emulation to prevent SQL injection.
*   **Strict CORS Policy**: Whitelists authorized origins while maintaining localhost development support.
*   **State-Free Signed CAPTCHAs**: Solves math challenges via signed HMAC tokens, stopping automated spam without holding heavy database session records.
*   **IP-Based Rate Limiting**: Throttles guest-facing submissions using dynamic temporary JSON tracking tables.

### 2. Administrative Security Perimeter
*   **Subdomain-Scoped Cookies**: Admin sessions use native PHP cookies configured with `Secure`, `HttpOnly`, and `SameSite=Lax`, restricted to `admin.oceanviewflats.com`.
*   **Argon2id Hashing**: Admin credentials use `PASSWORD_ARGON2ID` with high-security memory cost (64MB) and time cost (4 iterations).
*   **Brute-Force Lockout**: 5 failed consecutive login attempts trigger an automatic 15-minute account lockout recorded in `admin_users`.
*   **CLI Provisioning**: User accounts can only be created or modified via the command line (`scripts/create-admin-user.php`), eliminating web-based privilege escalation.
*   **Immutable Audit Trail**: All administrative actions (reservation cancellations, date changes, door code overrides, calendar holds) are recorded with timestamps, user IDs, and client IP addresses in `admin_audit_logs`.

### 3. Centralized Database Schema Migrations
*   No database creations or `ALTER TABLE` operations occur inside client transactional API endpoints.
*   Database updates are compiled under [`scripts/schema.sql`](../scripts/schema.sql) and ran through the CLI-exclusive self-healing migrator [`scripts/migrate.php`](../scripts/migrate.php).

### 4. Double-Charge Protection (Idempotency Engine)
Payments utilize unique reservation identifier codes as idempotency keys. Pre-payment steps check the `payment_idempotency` table before routing to MercadoPago, short-circuiting duplicate transactions instantly.

---

## ⚙️ Compilation Build Cycle (`npm run build`)

Static page assets are generated and post-processed in an automated chained command loop:

```text
+------------------+     1. Clean dist/     +-----------------------------+
|    render.tsx    | ---------------------> | Compile prices.csv to JSON  |
+------------------+                        +-----------------------------+
         │
         │ 2. Iterate pages.ts & languages
         ▼
+------------------+     3. Inject Shell    +-----------------------------+
| renderToStatic() | ---------------------> |  Write HTML to output paths |
+------------------+                        +-----------------------------+
         │
         │ 4. Compile Tailwind CSS via Vite
         ▼
+------------------+     5. Minify Scripts  +-----------------------------+
|    vite build    | ---------------------> | Distribute dist/ assets     |
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

## 🌐 Dynamic Localizations Bridge

We maintain clean separation between pre-compiled static structures and dynamic script notifications:
*   **Core Dictionary**: Standard UI terms are mapped inside the central React translation sheet ([`src/i18n/dict.ts`](../src/i18n/dict.ts)).
*   **Bridging System**: React embeds translated terms inside custom parent HTML node attributes (e.g., `data-msg-success="Trans_Val"`).
*   **Client Parsing**: Native JS scripts query these attributes on load. This completely prevents hardcoded English terms from leaking onto Spanish, French, Italian, German, or Japanese viewports.
*   **API Translation Keys**: Dynamic server outputs are matched against the validated client request language query and loaded from the central backend dictionary [`public/api/translations.php`](../public/api/translations.php).

---

## ⚡ Core Web Vitals (PageSpeed) Protection
*   **Zero Global Script Loading**: Third-party integrations (like MercadoPago's `sdk.mercadopago.com/js/v2`) are completely omitted from the global HTML base template.
*   **Asynchronous On-Demand Load**: A lightweight dynamic Promise loader is configured inside `main.js`. It fetches the script asynchronously *only* when the direct booking calendar form unhides, achieving perfect performance metrics on static text-heavy informational pages.