import Home from '../pages/Home';
import Oceanview1707 from '../pages/Oceanview1707';
import Oceanview1606 from '../pages/Oceanview1606';
import { IMAGES } from '../constants/config';
import { Lang } from '../types';

export const BASE_URL = 'https://www.oceanviewflats.com';
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
        seoTitle: (t: any) => "Vacation Rentals | Santa Marta | OceanViewFlats",
        seoDescription: (t: any) => "Explore our beautiful beachfront properties in Santa Marta, Colombia. Book your direct stay at OceanViewFlats 1707 or 1606.",
        ogImage: IMAGES['1707'].hero,
        getStructuredData: (t: any, lang: Lang, baseUrl: string) => {
            const url = lang === 'en' ? baseUrl : `${baseUrl}/${lang}.html`;
            return JSON.stringify({
                "@context": "https://schema.org",
                "@type": "WebSite",
                "name": "OceanViewFlats",
                "url": url,
                "description": "Premium beachfront vacation rentals in Santa Marta, Colombia."
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
                "additionalType": "http://www.productontology.org/id/Vacation_rental",
                "identifier": "1500108514798091235",
                "name": "OceanViewFlats 1707",
                "description": t.seoDescription,
                "image": [
                    `${baseUrl}/${IMAGES['1707'].hero}`,
                    `${baseUrl}/${IMAGES['1707'].nomad}`,
                    `${baseUrl}/${IMAGES['1707'].bedroom}`,
                    `${baseUrl}/${IMAGES['1707'].pool}`,
                    `${baseUrl}/${IMAGES['1606'].hero}`,
                    `${baseUrl}/${IMAGES['1606'].romantic}`,
                    `${baseUrl}/${IMAGES['1606'].bedroom}`,
                    `${baseUrl}/${IMAGES['1606'].pool}`
                ],
                "url": url,
                "address": {
                    "@type": "PostalAddress",
                    "streetAddress": "Calle 26 # 2-80. Playa Salguero",
                    "addressLocality": "Santa Marta",
                    "addressRegion": "Magdalena",
                    "postalCode": "470006",
                    "addressCountry": "CO"
                },
                "geo": {
                    "@type": "GeoCoordinates",
                    "latitude": 11.1876481,
                    "longitude": -74.2313921
                },
                "containsPlace": [
                    { "@type": "Room", "name": "Master Bedroom" },
                    { "@type": "Room", "name": "Guest Bedroom" }
                ],
                "numberOfRooms": 2,
                "occupancy": {
                    "@type": "QuantitativeValue",
                    "value": 6
                },
                "petsAllowed": false,
                "amenityFeature": [
                    { "@type": "LocationFeatureSpecification", "name": t.amWifi, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amOcean, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amPool, "value": true }
                ],
                "aggregateRating": {
                    "@type": "AggregateRating",
                    "ratingValue": "4.9",
                    "reviewCount": "24"
                },
                "review": [
                    {
                        "@type": "Review",
                        "author": { "@type": "Person", "name": "Guest" },
                        "datePublished": "2024-01-15",
                        "reviewRating": { "@type": "Rating", "ratingValue": "5" },
                        "reviewBody": "Amazing stay, beautiful views."
                    }
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
                "additionalType": "http://www.productontology.org/id/Vacation_rental",
                "identifier": "1500108514798091235",
                "name": "OceanViewFlats 1606",
                "description": t.seo1606Description,
                "image": [
                    `${baseUrl}/${IMAGES['1606'].hero}`,
                    `${baseUrl}/${IMAGES['1606'].romantic}`,
                    `${baseUrl}/${IMAGES['1606'].bedroom}`,
                    `${baseUrl}/${IMAGES['1606'].pool}`,
                    `${baseUrl}/${IMAGES['1707'].hero}`,
                    `${baseUrl}/${IMAGES['1707'].nomad}`,
                    `${baseUrl}/${IMAGES['1707'].bedroom}`,
                    `${baseUrl}/${IMAGES['1707'].pool}`,
                ],
                "url": url,
                "address": {
                    "@type": "PostalAddress",
                    "streetAddress": "Calle 26 # 2-80. Playa Salguero",
                    "addressLocality": "Santa Marta",
                    "addressRegion": "Magdalena",
                    "postalCode": "470006",
                    "addressCountry": "CO"
                },
                "geo": {
                    "@type": "GeoCoordinates",
                    "latitude": 11.1876481,
                    "longitude": -74.2313921
                },
                "containsPlace": [
                    { "@type": "Room", "name": "Master Bedroom" },
                    { "@type": "Room", "name": "Guest Bedroom" }
                ],                "numberOfRooms": 2,
                "occupancy": {
                    "@type": "QuantitativeValue",
                    "value": 6
                },
                "petsAllowed": false,
                "amenityFeature": [
                    { "@type": "LocationFeatureSpecification", "name": t.amWifi, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amOcean, "value": true },
                    { "@type": "LocationFeatureSpecification", "name": t.amPool, "value": true }
                ],
                "aggregateRating": {
                    "@type": "AggregateRating",
                    "ratingValue": "5",
                    "reviewCount": "2"
                },
                "review": [
                    {
                        "@type": "Review",
                        "author": { "@type": "Person", "name": t.review1Author },
                        "datePublished": "2026-04-02",
                        "reviewRating": { "@type": "Rating", "ratingValue": "5" },
                        "reviewBody": t.review1Text
                    },
                    {
                        "@type": "Review",
                        "author": { "@type": "Person", "name": t.review2Author },
                        "datePublished": "2026-03-20",
                        "reviewRating": { "@type": "Rating", "ratingValue": "5" },
                        "reviewBody": t.review2Text
                    }
                ]
            });
        },
        scripts: ['js/main.js']
    }
];