import Home from '../pages/Home';
import Oceanview1707 from '../pages/Oceanview1707';
import Oceanview1606 from '../pages/Oceanview1606';
import { IMAGES } from '../constants/config';
import { Lang } from '../types';

export const BASE_URL = 'https://oceanview1707.com';
export const LANGUAGES: Lang[] = ['en', 'es', 'fr', 'it', 'de', 'ja'];

export const getHrefLangTags = (baseUrl: string, path: string, languages: Lang[]) => {
    const basePath = path ? `${baseUrl}/${path}` : baseUrl;
    return languages.map(l => {
        const url = l === 'en' ? basePath : `${basePath}/${l}.html`;
        return `    <link rel="alternate" hreflang="${l}" href="${url}" />`;
    }).join('\n') + `\n    <link rel="alternate" hreflang="x-default" href="${basePath}" />`;
};

export interface PageConfig {
    id: string;
    path: string;
    component: any; // React Component
    filename: (lang: Lang) => string;
    getStructuredData: (t: any, lang: Lang, baseUrl: string) => string;
    scripts: string[];
    seoTitle?: (t: any) => string;
    seoDescription?: (t: any) => string;
    ogImage?: string;
}

export const pages: PageConfig[] = [
    {
        id: 'home',
        path: '',
        component: Home,
        filename: (lang: Lang) => lang === 'en' ? 'index.html' : `${lang}.html`,
        seoTitle: (t: any) => "Vacation Rentals | Santa Marta",
        seoDescription: (t: any) => "Explore our beautiful beachfront properties in Santa Marta, Colombia. Book your next stay with us.",
        ogImage: IMAGES['1707'].hero,
        getStructuredData: (t: any, lang: Lang, baseUrl: string) => {
            const url = lang === 'en' ? baseUrl : `${baseUrl}/${lang}.html`;
            return JSON.stringify({
                "@context": "https://schema.org",
                "@type": "WebSite",
                "name": "Santa Marta Vacation Rentals",
                "url": url
            });
        },
        scripts: ['js/main.js']
        },
        {
        id: 'Oceanview1707',
        path: 'Oceanview1707',
        component: Oceanview1707,
        filename: (lang: Lang) => lang === 'en' ? 'Oceanview1707/index.html' : `Oceanview1707/${lang}.html`,
        seoTitle: (t: any) => t.seoTitle,
        seoDescription: (t: any) => t.seoDescription,
        ogImage: IMAGES['1707'].hero,
        getStructuredData: (t: any, lang: Lang, baseUrl: string) => {
            const url = lang === 'en' ? `${baseUrl}/Oceanview1707` : `${baseUrl}/Oceanview1707/${lang}.html`;
            return JSON.stringify({
                "@context": "https://schema.org",
                "@type": "VacationRental",
                "name": "Oceanview Flat 1707",
                "description": t.seoDescription,
                "image": `${baseUrl}/${IMAGES['1707'].hero}`,
                "url": url,
                "address": {
                    "@type": "PostalAddress",
                    "addressLocality": "Santa Marta",
                    "addressRegion": "Magdalena",
                    "addressCountry": "CO"
                },
                "numberOfRooms": 2,
                "petsAllowed": false,
                "amenityFeature": [
                    { "@type": "LocationFeatureSpecification", "name": t.amWifi, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amOcean, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amPool, "value": true }
                ]
            });
        },
        scripts: ['js/main.js']
    },
    {
        id: 'Oceanview1606',
        path: 'Oceanview1606',
        component: Oceanview1606,
        filename: (lang: Lang) => lang === 'en' ? 'Oceanview1606/index.html' : `Oceanview1606/${lang}.html`,
        seoTitle: (t: any) => t.seo1606Title,
        seoDescription: (t: any) => t.seo1606Description,
        ogImage: IMAGES['1606'].hero,
        getStructuredData: (t: any, lang: Lang, baseUrl: string) => {
            const url = lang === 'en' ? `${baseUrl}/Oceanview1606` : `${baseUrl}/Oceanview1606/${lang}.html`;
            return JSON.stringify({
                "@context": "https://schema.org",
                "@type": "VacationRental",
                "name": "Oceanview Flat 1606",
                "description": t.seo1606Description,
                "image": `${baseUrl}/${IMAGES['1606'].hero}`,
                "url": url,
                "address": {
                    "@type": "PostalAddress",
                    "addressLocality": "Santa Marta",
                    "addressRegion": "Magdalena",
                    "addressCountry": "CO"
                },
                "numberOfRooms": 2,
                "petsAllowed": false,
                "amenityFeature": [
                    { "@type": "LocationFeatureSpecification", "name": t.amWifi, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amOcean, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amPool, "value": true }
                ]
            });
        },
        scripts: ['js/main.js']
    }
];