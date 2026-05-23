# Traditional SEO Best Practices

Use these guidelines when generating a plan for traditional Search Engine Optimization.

## 1. Semantic HTML Structure
*   Ensure exactly one `<h1>` per page representing the core topic.
*   Use sequential heading tags (`<h2>`, `<h3>`) to establish a logical hierarchy. Do not skip levels.
*   Wrap main content in `<main>`, navigation in `<nav>`, headers in `<header>`, and footers in `<footer>`.
*   Use `<article>` for self-contained content and `<section>` for thematic groupings.

## 2. Meta Tags & Document Head
*   **Title Tag:** Keep under 60 characters. Place primary keywords towards the beginning.
*   **Meta Description:** Keep under 160 characters. Must be a compelling summary that drives Click-Through Rate (CTR). Include a call-to-action if appropriate.
*   **Canonical URL:** Include a `<link rel="canonical" href="..." />` to prevent duplicate content issues.

## 3. Multilingual & Internationalization
*   If the site supports multiple languages, ensure rigorous `hreflang` implementation.
*   Provide a `<link rel="alternate" hreflang="xx" href="..." />` for every localized version, plus an `x-default` tag for fallback.

## 4. Open Graph & Social Sharing
*   Include `og:title`, `og:description`, `og:image`, `og:url`, and `og:type` to optimize appearance when shared on platforms like X, Facebook, Slack, and WhatsApp.
*   Include Twitter card meta tags (`twitter:card`, `twitter:title`, `twitter:image`).

## 5. Media Optimization
*   Every `<img>` must have a descriptive `alt` attribute for accessibility and image search SEO.
*   Prefer modern formats (WebP/AVIF). Provide width and height attributes to prevent Cumulative Layout Shift (CLS).
