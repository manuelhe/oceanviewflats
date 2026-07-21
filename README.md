# OceanViewFlats Static Site Generator & Secure REST API

High-performance, multilingual static site generator and secure backend REST API for OceanViewFlats' premium beachfront vacation rental properties in Playa Salguero, Santa Marta, Colombia.

Built on top of **React**, **Vite**, and **Tailwind CSS v4** on the frontend, combined with a secure **PHP & MySQL** REST backend. This project compiles React components into SEO/GEO-optimized static HTML files with structured JSON-LD data for rich search snippets and AI search engine discovery, while serving direct booking transactions and form inquiries through a hardened server API.

---

## 🚀 Key Features

*   **Component-Driven Static Generation**: Author web pages in React 19 and compile them into zero-dependency, ultra-fast static HTML files.
*   **Centralized Parameter-Driven Themes**: Thematic variations between properties (Slate vs. Stone, Red vs. Rose) are dynamically resolved from a single style-agnostic component layer powered by a centralized configuration dictionary ([`src/constants/theme.ts`](src/constants/theme.ts)).
*   **Fully Multilingual (6 Languages)**: Native support for English (`en`), Spanish (`es`), French (`fr`), Italian (`it`), German (`de`), and Japanese (`ja`) with dynamic language detection and seamless navigation.
*   **SEO & GEO (Generative Engine Optimization)**:
    *   Automatic generation of structured **JSON-LD Schema** (LodgingBusiness, WebSite, WebPage, FAQPage) for AI engine search indexing.
    *   Canonical links and fully mapped `hreflang` tags generated for each locale.
    *   Exclusion of private pages (guest guide, registry) from the automatically generated `sitemap.xml`.
*   **Secure Backend REST API (`public/api/`)**:
    *   **Direct Booking Engine**: Handles quote validation, local database persistence with self-healing MySQL tables, language tracking, and email dispatches.
    *   **Secure Inquiries**: Math-Captcha protected endpoints for contact submissions and digital guest check-ins (`public/api/contact-processor.php`, `public/api/registry-processor.php`).
    *   **MercadoPago Checkout Bricks**: Custom inline checkout integration utilizing the native Payment Brick. This allows transparent processing of PSE transfers, credit/debit cards, and Efecty cash vouchers server-to-server (`public/api/payment.php`) with robust double-charge idempotency protection.
    *   **Gateway Webhooks**: Integrates with MercadoPago webhooks to process off-line payment confirmations (PSE/Efecty) and dispatch localized confirmation/payment emails.
    *   **Robust Security Hardening**:
        *   Restricted file listings (`Options -Indexes`) and blocked direct access to utility scripts (`translations.php`, `utils.php`) inside `.htaccess`.
        *   Strict CORS rules mapping inquiries explicitly to official host domains (`https://www.oceanviewflats.com`).
        *   Direct address bar submissions blocked via empty referrer filters against generic browser user-agents.
*   **Dynamic Guest Guide (`/guide`)**: A secure guest welcome center that processes URL query parameters (`guest`, `property`, `check_in`, `check_out`, `code`) to render localized arrival instructions, secure temporary entry codes, and one-click copyable Wi-Fi configurations.
*   **Interactive Guest Registry (`/registry`)**: Seamless digital guest check-in form collecting mandatory building security info.
*   **Asset Processing**: Modern styling via **Tailwind CSS v4** bundled through Vite, with client-side interactive JS files minified using **Terser**.
*   **Centralized Database CLI Migrations**: No dynamic schema mutations occur inside public runtime PHP scripts. Relational table structures and index specifications are updated using the dynamic self-healing CLI script (`scripts/migrate.php`).

---

## 📁 Project Structure

```text
├── .agents/                    # Workspace agent guidelines and custom skills
├── .env.example                # Sample environment variables
├── AGENTS.md                   # Agent guidelines and workflow orchestration
├── LICENSE                     # MIT License
├── package.json                # NPM scripts and dependencies
├── render.tsx                  # Core Static HTML Generator script
├── vite.config.ts              # Vite asset compilation configuration (Tailwind v4)
├── scripts/                    # Relational database CLI assets
│   ├── schema.sql              # Master MySQL schema definitions
│   └── migrate.php             # CLI-exclusive self-healing database migration runner
├── public/                     # Static assets, raw client-side scripts, and REST API
│   ├── favicon.svg             # Multi-format favicons
│   ├── images/                 # Property high-res gallery images
│   ├── js/                     # Interactivity scripts (lang-detect, main, registry, guide)
│   └── api/                    # Secure REST API (PHP backend)
│       ├── .htaccess           # Security, directory listing blocks, and access controls
│       ├── book-request.php    # Local database booking logger & confirmation dispatcher
│       ├── payment.php         # Processes server-to-server Checkout Bricks API payments
│       ├── contact-processor.php  # Contact form handler with cryptographic captcha check
│       ├── registry-processor.php # Multi-step guest check-in database logger
│       ├── mercadopago-webhook.php # MercadoPago transaction webhook callback endpoint
│       ├── translations.php    # Centralized backend dynamic email & response dictionary
│       └── utils.php           # CORS, Math Captchas, and strict get_validated_lang() utilities
├── src/                        # React source application files
│   ├── components/             # Reusable UI components (Navbar, Footer, Gallery, FAQ)
│   ├── config/                 # Page lists, routing setup, metadata, and JSON-LD builders
│   │   └── pages.ts            # Defines page structure, SEO titles, descriptions, and metadata
│   ├── constants/              # Asset links, properties themes, and configs
│   │   ├── config.ts           # Core endpoints and base asset definitions
│   │   └── theme.ts            # Theme dictionary mapping Slate/Stone tokens and properties images
│   ├── i18n/                   # Multilingual dictionaries
│   │   └── dict.ts             # 6-language translation dictionaries
│   ├── pages/                  # React layout components (Home, Oceanview1707, Guide, etc.)
│   ├── style.css               # Core Tailwind CSS imports and custom utility classes
│   ├── templates/              # HTML base page structural templates
│   │   └── base.ts             # Injects React markup, scripts, meta tags, and schema JSON
│   ├── types/                  # TypeScript interface definitions
│   └── utils/                  # Localization and helper utilities
└── tasks/                      # Project workflow task files (todo, lessons)
```

---

## 🛠️ Getting Started

### Prerequisites

Ensure you have [Node.js](https://nodejs.org/) installed (version 18 or above recommended).

### Installation

1. Clone this repository to your local system.
2. Install dependencies:
   ```bash
   npm install
   ```

### Database Migration

Before deploying the PHP REST API, run the database migrations tool to provision tables and index structures on your remote MySQL database instance:

```bash
php scripts/migrate.php
```

### Local Development & Live Server

Since this is a static site generator, you can compile and view your static files. You can run a local HTTP server to preview the generated files in your browser:

```bash
# Compile and build everything to /dist
npm run build

# Start a local preview server
npx serve dist
```

---

## ⚙️ Environment Variables

The project uses environment variables to handle secure transactions, notifications, and form spam validation. To configure these, create a `.env` file in the root directory:

```env
# Notification Settings
RECIPIENT_EMAIL=contact@mail.com

# Cryptographic Captcha Security
CAPTCHA_SECRET=your_secure_random_salt_secret

# Third-Party Synchronization
GOOGLE_SHEET_WEBAPP_URL=https://script.google.com/macros/s/macro_id/exec

# Database Credentials
DB_HOST=127.0.0.1
DB_NAME=oceanviewflats_db
DB_USER=root
DB_PASS=local_db_password

# MercadoPago API Credentials (Checkout Bricks)
MERCADOPAGO_PUBLIC_KEY=APP_USR-xxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MERCADOPAGO_ACCESS_TOKEN=APP_USR-xxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
MERCADOPAGO_SANDBOX=true
```

---

## 📦 Build & Compilation Details

When you run `npm run build`, the following steps execute sequentially:

1.  **Cleanup**: Removes any existing `dist/` directory.
2.  **Asset Mirroring**: Copies all raw files from the `public/` folder directly to `dist/` (including the `api/` endpoints).
3.  **Vite Bundling**: Invokes Vite to compile, tree-shake, and optimize Tailwind CSS v4 styles (`src/style.css`) into `dist/css/style.css`.
4.  **Static Page Generation (`render.tsx`)**:
    *   Loops through each page entry inside `src/config/pages.ts`.
    *   Iterates across each language locale (`en`, `es`, `fr`, `it`, `de`, `ja`).
    *   Uses React's `renderToStaticMarkup` to generate the HTML blocks.
    *   Combines the markup with the HTML base template injecting `hreflang`, canonical tags, metadata, page scripts, and unique SEO JSON-LD schema.
    *   **CI Dynamic Public Key Injection**: During deployment pipelines, the GitHub Actions process automatically injects the production `MERCADOPAGO_PUBLIC_KEY` secret into `src/constants/config.ts` prior to building, preventing any exposed tokens or environmental lookups.
    *   Saves the compiled HTML files into relative directories (e.g., `dist/index.html`, `dist/es.html`, `dist/guide/index.html`, `dist/guide/es.html`).
5.  **Sitemap Generation**: Generates `dist/sitemap.xml` containing all indexable, public multilingual pages (excluding `/404`, `/registry`, and `/guide`).
6.  **Script Minification**: Runs `terser` to compress and optimize client-side interactive logic scripts:
    *   `lang-detect.js`: Detects browser language preferences and redirects appropriately.
    *   `main.js`: Core landing page animations, captcha matching, dynamic MercadoPago SDK async preloader, and dynamic Checkout Bricks iframe mount handlers.
    *   `registry.js`: Dynamic multipage guest check-in form validation.
    *   `guide.js`: Interactive welcoming cards, URL parameter parsing, and temporary access codes with feedback.

---

## 🚀 Deployment

The compiled assets reside in the `dist/` folder after running `npm run build`. This directory is ready for hosting on any modern static deployment provider supporting PHP backends or separate static hosting + server setups:

*   **Classic Hostings (with Apache + PHP)**: Deploy the entire contents of the `dist/` folder. The static HTML works natively, and the `api/` directory serves dynamic REST actions out-of-the-box.
*   **Static Hostings (Firebase/Cloudflare Pages)**: Deploy the compiled static pages to your provider, and proxy `/api/*` references to an Apache/PHP-compatible server running the `api/` directory.
