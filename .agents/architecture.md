# OceanViewFlats Architecture Overview

Welcome to the OceanViewFlats project! This document outlines the core architecture, build process, and tech stack to help guide future tasks.

## Tech Stack & Core Philosophy
This project is a **custom Static Site Generator (SSG)** optimized for absolute peak performance, SEO/GEO (Generative Engine Optimization), and cheap hosting. It leverages modern tools without the bloat of a full meta-framework (like Next.js) because the content is largely static.

*   **UI/Components:** React (with TypeScript), Lucide React (Icons).
*   **Styling:** Tailwind CSS v4.
*   **Build Pipeline:** `tsx` (TypeScript executor) + React `renderToStaticMarkup` + Vite (for Tailwind CSS compilation) + `terser` (for JS minification).
*   **Interactivity:** Vanilla JavaScript (`public/js/main.js` and `public/js/lang-detect.js`).
*   **CI/CD:** GitHub Actions with automated SFTP deployment.

### Why Vanilla JS instead of React Hydration?
To achieve near-instant load times and perfect lighthouse scores, the React components are only used to **generate static HTML**. They do *not* hydrate on the client. All client-side interactivity (navbar scroll effects, language dropdown toggle, interactive booking calendar) is handled by lightweight, vanilla JavaScript located in `public/js/`. 
*   **Rule of Thumb:** If you need a new interactive element (like a modal or a carousel), build the structural skeleton in the React component, add the necessary IDs or data-attributes, and write the logic in `public/js/main.js`.

## Directory Structure

```text
/
├── .github/
│   └── workflows/
│       └── deploy.yml      # CI/CD pipeline for automated SFTP deployments
├── public/                 # Static assets copied directly to the output 'dist' folder
│   ├── images/             # Optimized WebP imagery (grouped by flat prefix: 1707-, 1606-)
│   ├── js/                 # Client-side Vanilla JS
│   │   ├── main.js         # Site interactivity (calendar, nav, language dropdown)
│   │   └── lang-detect.js  # Automatic browser language detection and redirection
│   ├── favicon.svg         # Site favicon
│   ├── robots.txt          # Explicit crawler permissions
│   └── llms.txt            # Markdown-formatted summary explicitly for AI LLM ingestion
├── src/
│   ├── components/         # Reusable React components (Navigation, Footer, Hero)
│   │   └── warm/           # Themed variations of components (for the romantic/family 1606 flat)
│   ├── config/
│   │   └── pages.ts        # The Central Router & Configuration. Defines paths, metadata, and JSON-LD schemas.
│   ├── constants/
│   │   └── config.ts       # Global constants (e.g., Airbnb URLs, Image paths)
│   ├── i18n/
│   │   └── dict.ts         # Central translation dictionary for all 6 supported languages (en, es, fr, it, de, ja)
│   ├── pages/              # Top-level Page components (Home, Oceanview1707, Oceanview1606)
│   └── templates/
│       └── base.ts         # The master HTML document wrapper (`<!DOCTYPE html>...`)
├── render.tsx              # The custom build script. It iterates over `pages.ts` and `LANGUAGES` to emit HTML.
├── vite.config.ts          # Vite configuration solely dedicated to building Tailwind v4 CSS.
└── package.json            # Contains the core `build` script (`tsx render.tsx && vite build && npx terser...`)
```

## The Build Process (`npm run build`)
The entire site is generated via a custom script located in `render.tsx` and chained npm commands. When you execute `npm run build`:
1.  **Clean & Scaffold:** The `dist/` directory is deleted and recreated.
2.  **Asset Copy:** Everything in `/public` is copied exactly as-is into `/dist`.
3.  **HTML Generation Loop:** `render.tsx` iterates through the `pages` array defined in `src/config/pages.ts`. For each page, it iterates through the 6 languages in `LANGUAGES`.
4.  **React Render:** It calls `renderToStaticMarkup(<PageComponent lang={lang} />)` to convert the React component tree into a raw HTML string.
5.  **Template Wrapping:** The raw HTML is injected into the `baseTemplate` (`src/templates/base.ts`), along with the computed asset prefixes, localized SEO metadata, Open Graph tags, canonical URLs, `hreflang` tags, and highly-detailed `JSON-LD` structured data.
6.  **Sitemap Generation:** A `sitemap.xml` is dynamically constructed and written to `/dist`.
7.  **CSS Compilation:** Vite spins up to process `src/style.css`, parsing the newly generated HTML files to extract the exact Tailwind utility classes used, and outputs the minified CSS to `dist/css/style.css`.
8.  **JS Minification:** Finally, `terser` runs on the JavaScript files in `public/js/` (like `main.js` and `lang-detect.js`), compressing and mangling them directly into the `dist/js/` folder to minimize payload sizes.

## Core Project Tenets

### 1. Multi-Language (i18n) by Default
Every text string visible to the user must be abstracted into `src/i18n/dict.ts`. Never hardcode text inside a component.
*   **Automatic Redirection:** The `public/js/lang-detect.js` script automatically redirects users from the English index to their browser's preferred language upon their first visit.
*   **Preference Persistence:** Once a user views a localized page or explicitly selects a language from the dropdown, their choice is saved to `localStorage` to prevent forceful redirecting against their wishes.

### 2. Asset Prefixes for Nested Routes
Because the site generates nested routes (e.g., `dist/Oceanview1707/es.html`), absolute paths to local assets (like `/images/hero.webp` or `/js/main.js`) break when testing locally without a server. 
*   **The Fix:** `render.tsx` computes an `assetPrefix` (either `./` for root files or `../` for nested files). This prefix is passed down as a prop to `AppProps` and must be prepended to all local `<img>` sources and `<script>`/`<link>` tags.

### 3. SEO and GEO (Generative Engine Optimization) Focus
This site is heavily optimized for both Google and AI engines (ChatGPT, Perplexity).
*   **Semantic HTML:** Ensure strict heading hierarchy (`<h1>` down to `<h3>`). Use `<main>`, `<section>`, and explicit `<article>` wrappers.
*   **Structured Data:** Every page config (`src/config/pages.ts`) defines an explicit `getStructuredData` function. These schemas strictly adhere to Google Search Console requirements for the `VacationRental` type, meaning `containsPlace` must granularly define nested rooms, occupancy, features, and precise coordinates.
*   **Crawler Guides:** Both `public/robots.txt` (to explicitly allow major AI bots) and `public/llms.txt` (to feed structured markdown summaries to LLMs) must be manually updated if major architectural or property features change.