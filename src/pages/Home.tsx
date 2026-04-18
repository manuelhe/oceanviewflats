import React from 'react';
import { AppProps } from '../types';
import { Navigation } from '../components/Navigation';
import { Footer } from '../components/Footer';
import { dict } from '../i18n/dict';
import { IMAGES } from '../constants/config';

export default function Home({ lang, assetPrefix = './' }: AppProps) {
  const t = dict[lang];
  
  return (
    <div className="min-h-screen flex flex-col bg-slate-50 font-sans text-slate-800 scroll-smooth">
      <Navigation isScrolled={true} lang={lang} />
      <main className="flex-grow pt-32 pb-24 px-6 max-w-6xl mx-auto w-full">
        <h1 className="text-4xl md:text-5xl font-extrabold mb-4 text-slate-900">
          Welcome to our Properties
        </h1>
        <p className="text-lg text-slate-600 mb-12">
          Discover your next perfect stay.
        </p>
        
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
          <a 
            href={lang === 'en' ? '/Oceanview1707' : `/Oceanview1707/${lang}.html`} 
            className="group block bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden hover:shadow-xl transition-all duration-300"
          >
            <div className="h-64 relative overflow-hidden">
                <img 
                  src={`${assetPrefix}${IMAGES['1707'].hero}`} 
                  className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                  alt={t.heroTitle} 
                />
            </div>
            <div className="p-6">
                <h2 className="text-2xl font-bold mb-2 text-slate-900 group-hover:text-[#FF5A5F] transition-colors">{t.heroTitle}</h2>
                <p className="text-slate-500">{t.heroLocation}</p>
            </div>
          </a>

          <a 
            href={lang === 'en' ? '/Oceanview1606' : `/Oceanview1606/${lang}.html`} 
            className="group block bg-white rounded-2xl shadow-sm border border-stone-200 overflow-hidden hover:shadow-xl transition-all duration-300"
          >
            <div className="h-64 relative overflow-hidden">
                <img 
                  src={`${assetPrefix}${IMAGES['1606'].hero}`} 
                  className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" 
                  alt={t.hero1606Title} 
                />
            </div>
            <div className="p-6">
                <h2 className="text-2xl font-bold mb-2 text-stone-900 group-hover:text-rose-600 transition-colors">{t.hero1606Title}</h2>
                <p className="text-stone-500">{t.heroLocation}</p>
            </div>
          </a>
        </div>
      </main>
      <Footer lang={lang} />
    </div>
  );
}
