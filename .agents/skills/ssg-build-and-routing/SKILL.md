---
name: ssg-build-and-routing
description: Master custom React Static Site Generator (SSG) compiler, dynamic pages router, sitemaps, and relative path asset-prefix compilation.
---

# Custom Static Site Generator (SSG) & Routing Standards

You are an expert static site generation (SSG) and build engineer. This guide enforces the strict architectural compile-time workflow of the OceanViewFlats React generator.

---

## 🏗️ Build Lifecycle Flow (`render.tsx` -> `dist/`)

The application does not use server-side React or hydration. It utilizes a custom node TypeScript build pipeline.

```text
+---------------------+     Loop through page configs     +---------------------------+
| src/config/pages.ts | --------------------------------> |     render.tsx (node)     |
+---------------------+                                   +---------------------------+
                                                                        |
                                                                        | 1. renderToStaticMarkup()
                                                                        | 2. Wrap baseTemplate()
                                                                        v
                                                          +---------------------------+
                                                          |  dist/[route]/[lang].html |
                                                          +---------------------------+
```

---

## 🛠️ Core Build Steps

1. **Clean Directory**: Wipes existing `dist/` artifacts.
2. **Compile Cached Data**: Reads seasonal night-by-night configurations (`public/data/prices.csv`) and generates `/dist/data/prices.json`.
3. **HTML Generator Loop**:
   * Reads all declared page routes in [`src/config/pages.ts`](src/config/pages.ts).
   * Iterates through the whitelisted language codes (`['en', 'es', 'fr', 'it', 'de', 'ja']`).
   * Computes the exact `assetPrefix` based on depth (e.g., `./` for root pages like `index.html`, or `../` for subdirectories like `Oceanview1707/index.html`).
   * Renders the React page component to static text using `ReactDOMServer.renderToStaticMarkup`.
4. **Master Template Wrap**: Injects the React string output, hreflang alternate links, detailed schema markup, and canonical tags into [`src/templates/base.ts`](src/templates/base.ts).
5. **Tailwind v4 CSS Extraction**: Vite scans compiled outputs to bundle utility CSS classes, minifying to `dist/css/style.css`.
6. **Javascript Compression**: Terser minifies all custom JS scripts in `public/js/` into `dist/js/`.

---

## 🌐 Adding New Pages or Routes

All routes must be centrally defined inside [`src/config/pages.ts`](src/config/pages.ts).

### Mandatory Route Properties:
*   `id`: Unique alphanumeric identifier (e.g., `'about'`).
*   `component`: React Page component instance (with typing `React.ComponentType<any>`).
*   `getSlug(lang)`: Compiles the output file naming scheme (e.g., `es.html` or nested subfolder structures like `Oceanview1707/es.html`).
*   `getMetadata(lang)`: Generates strict, translated SEO `<title>` and `<meta name="description">` blocks.
*   `getStructuredData(lang)`: Emits complete JSON-LD configurations mapped accurately to schema.org recommendations (e.g., `VacationRental`, `WebSite`).

---

## 🔗 The Golden Rule: Asset Prefixing

Because the compiler outputs nested directory routing structures, **never use hardcoded absolute local paths** (e.g. `/images/hero.webp` or `/js/main.js`). They will break on subdirectory environments and local static file previews.

*   Always pass down the `assetPrefix` prop generated in `render.tsx` through all components.
*   Prepend `assetPrefix` to every local relative link:
    ```typescript
    <img src={`${assetPrefix}images/example.webp`} alt="Example" />
    <script src={`${assetPrefix}js/main.js`}></script>
    ```
