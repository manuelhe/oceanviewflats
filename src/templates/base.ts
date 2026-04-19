interface TemplateProps {
  markup: string;
  lang: 'en' | 'es' | 'fr' | 'it' | 'de' | 'ja';
  title: string;
  description: string;
  url: string;
  baseUrl: string;
  ogImage: string;
  hrefLangTags: string;
  structuredData: string;
  assetPrefix: string;
  customScripts?: string[];
}

export const baseTemplate = ({
  markup,
  lang,
  title,
  description,
  url,
  baseUrl,
  ogImage,
  hrefLangTags,
  structuredData,
  assetPrefix,
  customScripts = []
}: TemplateProps) => `<!DOCTYPE html>
<html lang="${lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>${title}</title>
    <meta name="description" content="${description}">
    <link rel="icon" type="image/svg+xml" href="${assetPrefix}favicon.svg">
    
    <!-- Open Graph / Social Sharing -->
    <meta property="og:site_name" content="OceanViewFlats">
    <meta property="og:type" content="website">
    <meta property="og:title" content="${title}">
    <meta property="og:description" content="${description}">
    <meta property="og:image" content="${ogImage}">
    <meta property="og:url" content="${url}">
    <link rel="canonical" href="${url}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="${title}">
    <meta name="twitter:description" content="${description}">
    <meta name="twitter:image" content="${ogImage}">

    <!-- Multilingual SEO -->
${hrefLangTags}

    <!-- Structured Data (JSON-LD) for Generative Engines & Rich Snippets -->
    <script type="application/ld+json">
        ${structuredData}
    </script>

    <link rel="stylesheet" href="${assetPrefix}css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .scroll-smooth { scroll-behavior: smooth; }
    </style>
</head>
<body>
    <div id="root">${markup}</div>
    ${customScripts.map(src => `<script src="${assetPrefix}${src}"></script>`).join('\n    ')}
</body>
</html>`;
