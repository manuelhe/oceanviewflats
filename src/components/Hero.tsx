import React from 'react';
import { MapPin, Calendar as CalendarIcon } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { IMAGES } from '../constants/config';

export const Hero = ({ lang, assetPrefix = './' }: { lang: Lang, assetPrefix?: string }) => {
  const t = dict[lang];

  return (
    <header className="relative h-[85vh] flex items-center justify-center pt-20">
      <div className="absolute inset-0 w-full h-full">
        <img 
          src={`${assetPrefix}${IMAGES['1707'].hero}`} 
          alt={t.heroImgAlt} 
          className="w-full h-full object-cover"
        />
        <div className="absolute inset-0 bg-gradient-to-b from-black/60 via-black/30 to-slate-900/80"></div>
      </div>
      <div className="relative z-10 text-center px-6 max-w-4xl mx-auto mt-10">
        <div className="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-md px-4 py-1.5 rounded-full text-white text-sm font-medium mb-6">
          <MapPin className="w-4 h-4" />
          <span>{t.heroLocation}</span>
        </div>
        <h1 className="text-4xl md:text-6xl font-extrabold text-white mb-6 leading-tight drop-shadow-lg">
          {t.heroTitle}
        </h1>
        <p className="text-lg md:text-2xl text-slate-200 mb-10 font-light drop-shadow">
          {t.heroSub}
        </p>
        <a href="#booking" className="inline-flex items-center space-x-2 bg-white text-slate-900 px-8 py-4 rounded-full font-bold text-lg hover:bg-slate-100 transition-colors shadow-2xl">
          <CalendarIcon className="w-5 h-5 text-[#FF5A5F]" />
          <span>{t.navBook}</span>
        </a>
      </div>
    </header>
  );
};
