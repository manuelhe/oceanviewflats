import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import fs from 'fs';
import path from 'path';
import { dict } from './src/i18n/dict';
import { IMAGES } from './src/constants/config';
import { baseTemplate } from './src/templates/base';
import { pages, BASE_URL, LANGUAGES, getHrefLangTags } from './src/config/pages';

const distDir = path.join(process.cwd(), 'dist');
const publicDir = path.join(process.cwd(), 'public');

if (fs.existsSync(distDir)) {
    fs.rmSync(distDir, { recursive: true, force: true });
    console.log('Cleaned dist/ directory');
}
fs.mkdirSync(distDir, { recursive: true });

// Copy public assets to dist
if (fs.existsSync(publicDir)) {
    fs.cpSync(publicDir, distDir, { recursive: true });
    console.log('Copied public assets to dist/');
}

pages.forEach(page => {
    const hrefLangTags = getHrefLangTags(BASE_URL, page.path, LANGUAGES);

    LANGUAGES.forEach(lang => {
        const t = dict[lang];
        const PageComponent = page.component;
        const filename = page.filename(lang);
        
        const depth = filename.split('/').length - 1;
        const assetPrefix = depth === 0 ? './' : '../'.repeat(depth);

        const markup = renderToStaticMarkup(<PageComponent lang={lang} assetPrefix={assetPrefix} />);
        
        const basePath = page.path ? `${BASE_URL}/${page.path}` : BASE_URL;
        const url = lang === 'en' ? basePath : `${basePath}/${lang}.html`;
        const structuredData = page.getStructuredData(t, lang, BASE_URL);

        const html = baseTemplate({
            markup,
            lang,
            title: page.seoTitle ? page.seoTitle(t) : t.seoTitle,
            description: page.seoDescription ? page.seoDescription(t) : t.seoDescription,
            url,
            baseUrl: BASE_URL,
            ogImage: `${BASE_URL}/${page.ogImage}`,
            hrefLangTags,
            structuredData,
            assetPrefix,
            customScripts: page.scripts
        });

        const outputPath = path.join(distDir, filename);
        
        const outputDir = path.dirname(outputPath);
        if (!fs.existsSync(outputDir)) {
            fs.mkdirSync(outputDir, { recursive: true });
        }
        
        fs.writeFileSync(outputPath, html);
        console.log(`Generated [${page.id}] -> ${outputPath}`);
    });
});
