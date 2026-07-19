import React from 'react';
import { MapPin, Calendar as CalendarIcon } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { IMAGES } from '../constants/config';
import { THEMES } from '../constants/theme';

export const Hero = ({ lang, propertyId = '1707', assetPrefix = './' }: { lang: Lang, propertyId?: '1707' | '1606', assetPrefix?: string }) => {
  const t = dict[lang];
  const theme = THEMES[propertyId];

  const gradientClass = propertyId === '1707'
    ? 'bg-gradient-to-b from-black/60 via-black/30 to-slate-900/80'
    : 'bg-gradient-to-b from-black/60 via-amber-900/30 to-stone-900/80';

  const iconColorClass = propertyId === '1707' ? 'text-[#FF5A5F]' : 'text-rose-600';
  const subtitleColorClass = propertyId === '1707' ? 'text-slate-200' : 'text-stone-200';
  const btnTxtColorClass = propertyId === '1707' ? 'text-slate-900' : 'text-stone-900';

  const titleText = propertyId === '1707' ? t.heroTitle : t.hero1606Title;
  const subText = propertyId === '1707' ? t.heroSub : t.hero1606Sub;

  return (
    <header className="relative h-[85vh] flex items-center justify-center pt-20">
      <div className="absolute inset-0 w-full h-full">
        <img 
          src={`${assetPrefix}${IMAGES[theme.imagesKey].hero}`} 
          alt={t.heroImgAlt} 
          className="w-full h-full object-cover"
          fetchPriority="high"
          loading="eager"
        />
        <div className={`absolute inset-0 ${gradientClass}`}></div>
      </div>
      <div className="relative z-10 text-center px-6 max-w-4xl mx-auto mt-10">
        <div className="inline-flex items-center space-x-2 bg-white/20 backdrop-blur-md px-4 py-1.5 rounded-full text-white text-sm font-medium mb-6">
          <MapPin className="w-4 h-4" />
          <span>{t.heroLocation}</span>
        </div>
        <h1 className="text-4xl md:text-6xl font-extrabold text-white mb-6 leading-tight drop-shadow-lg">
          {titleText}
        </h1>
        <p className={`text-lg md:text-2xl ${subtitleColorClass} mb-10 font-light drop-shadow`}>
          {subText}
        </p>
        <a href="#booking" className={`inline-flex items-center space-x-2 bg-white ${btnTxtColorClass} px-8 py-4 rounded-full font-bold text-lg hover:bg-slate-100 transition-colors shadow-2xl`}>
          <CalendarIcon className={`w-5 h-5 ${iconColorClass}`} />
          <span>{t.navBook}</span>
        </a>
      </div>
    </header>
  );
};
