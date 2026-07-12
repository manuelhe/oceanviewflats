# OceanViewFlats Static Site Generator

High-performance, multilingual static site generator for OceanViewFlats' premium beachfront vacation rental properties in Playa Salguero, Santa Marta, Colombia. Built on top of **React**, **Vite**, and **Tailwind CSS v4**, this project compiles React components into SEO/GEO-optimized static HTML files with structured JSON-LD data for rich search snippets and AI search engine discovery.

---

## 🚀 Key Features

*   **Component-Driven Static Generation**: Author web pages in React 19 and compile them into zero-dependency, ultra-fast static HTML files.
*   **Fully Multilingual (6 Languages)**: Native support for English (`en`), Spanish (`es`), French (`fr`), Italian (`it`), German (`de`), and Japanese (`ja`) with dynamic language detection and seamless navigation.
*   **SEO & GEO (Generative Engine Optimization)**:
    *   Automatic generation of structured **JSON-LD Schema** (LodgingBusiness, WebSite, WebPage, FAQPage) for AI engine search indexing.
    *   Canonical links and fully mapped `hreflang` tags generated for each locale.
    *   Exclusion of private pages (guest guide, registry) from the automatically generated `sitemap.xml`.
*   **Dynamic Guest Guide (`/guide`)**: A secure guest welcome center that processes URL query parameters (`guest`, `property`, `check_in`, `check_out`, `code`) to render localized arrival instructions, secure temporary entry codes, and one-click copyable Wi-Fi configurations.
*   **Interactive Guest Registry (`/registry`)**: Seamless digital guest check-in form collecting mandatory building security info.
*   **Secure Contact Inquiries**: Built-in interactive **Math Captcha** validation to protect against automated spam without external tracking scripts.
*   **Asset Processing**: Modern styling via **Tailwind CSS v4** bundled through Vite, with client-side interactive JS files minified using **Terser**.

---

## 📁 Project Structure

```text
├── .agents/                    # Workspace agent guidelines and lessons
├── .env.example                # Sample environment variables
├── AGENTS.md                   # Agent guidelines and workflow orchestration
├── LICENSE                     # MIT License
├── package.json                # NPM scripts and dependencies
├── render.tsx                  # Core Static HTML Generator script
├── vite.config.ts              # Vite asset compilation configuration (Tailwind v4)
├── public/                     # Static assets and raw client-side scripts
│   ├── favicon.svg             # Multi-format favicons
│   ├── images/                 # Property high-res gallery images
│   └── js/                     # Interactivity scripts (lang-detect, main, registry, guide)
├── src/                        # React source application files
│   ├── components/             # Reusable UI components (Navbar, Footer, Gallery, FAQ)
│   ├── config/                 # Page lists, routing setup, metadata, and JSON-LD builders
│   │   └── pages.ts            # Defines page structure, SEO titles, descriptions, and metadata
│   ├── constants/              # Asset links, property sizes, rules, and image configs
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

### Local Development & Live Server

Since this is a static site generator, you can compile and view your static files. 
You can run a local HTTP server to preview the generated files in your browser:

```bash
# Compile and build everything to /dist
npm run build

# Start a local preview server (using any simple HTTP server utility of choice)
npx serve dist
```

---

## ⚙️ Environment Variables

The project uses environment variables to handle form submissions and custom secure spam validation. To configure these, create a `.env` file in the root directory based on the `.env.example` file:

```env
# The email address where form inquiries should be sent
RECIPIENT_EMAIL=contact@mail.com

# Cryptographic salt for signing and verifying the Math Captcha
# Change this to a secure, random string in production
CAPTCHA_SECRET=your_secure_random_salt_secret

# Google Sheet API WebApp URL (used for logging registrations and contact inquiries)
GOOGLE_SHEET_WEBAPP_URL=https://script.google.com/macros/s/macro_id/exec
```

---

## 📦 Build & Compilation Details

When you run `npm run build`, the following steps execute sequentially:

1.  **Cleanup**: Removes any existing `dist/` directory.
2.  **Asset Mirroring**: Copies all raw files from the `public/` folder directly to `dist/`.
3.  **Vite Bundling**: Invokes Vite to compile, tree-shake, and optimize Tailwind CSS v4 styles (`src/style.css`) into `dist/css/style.css`.
4.  **Static Page Generation (`render.tsx`)**:
    *   Loops through each page entry inside `src/config/pages.ts`.
    *   Iterates across each language locale (`en`, `es`, `fr`, `it`, `de`, `ja`).
    *   Uses React's `renderToStaticMarkup` to generate the HTML blocks.
    *   Combines the markup with the HTML base template injecting `hreflang`, canonical tags, metadata, page scripts, and unique SEO JSON-LD schema.
    *   Saves the compiled HTML files into relative directories (e.g., `dist/index.html`, `dist/es.html`, `dist/guide/index.html`, `dist/guide/es.html`).
5.  **Sitemap Generation**: Generates `dist/sitemap.xml` containing all indexable, public multilingual pages (excluding `/404`, `/registry`, and `/guide`).
6.  **Script Minification**: Runs `terser` to compress and optimize client-side interactive logic scripts:
    *   `lang-detect.js`: Detects browser language preferences and redirects appropriately.
    *   `main.js`: Core landing page animations, captcha matching, and general contact form logic.
    *   `registry.js`: Dynamic multipage guest check-in form validation.
    *   `guide.js`: Interactive welcoming cards, URL parameter parsing, and temporary access codes with feedback.

---

## 🚀 Deployment

The compiled assets reside in the `dist/` folder after running `npm run build`. This directory is ready for hosting on any modern static deployment provider:

*   **Firebase Hosting** (Configure target directory to `dist`)
*   **Cloudflare Pages**
*   **Netlify**
*   **Vercel**
*   **GitHub Pages**
