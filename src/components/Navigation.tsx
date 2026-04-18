import React from 'react';
import { Waves } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { LanguageDropdown } from './LanguageDropdown';

interface NavigationProps {
  isScrolled: boolean;
  lang: Lang;
}

export const Navigation = ({ isScrolled, lang }: NavigationProps) => {
  const t = dict[lang];

  return (
    <nav className={`fixed w-full z-50 transition-all duration-300 ${isScrolled ? 'bg-white/90 backdrop-blur-md shadow-sm py-3' : 'bg-transparent py-5'}`} id="navbar">
      <div className="max-w-6xl mx-auto px-6 flex justify-between items-center">
        <div className={`font-bold text-xl tracking-tight flex items-center space-x-2 ${isScrolled ? 'text-[#FF5A5F]' : 'text-white'}`} id="nav-logo">
          <Waves className="w-6 h-6" />
          <span>Flat 1707</span>
        </div>
        <div className="flex items-center space-x-4">
          <LanguageDropdown currentLang={lang} isScrolled={isScrolled} />
          <a 
            href="#booking"
            className="bg-[#FF5A5F] hover:bg-[#FF424D] text-white px-5 py-2 rounded-full text-sm font-bold shadow-lg transition-transform hover:scale-105"
          >
            {t.navBook}
          </a>
        </div>
      </div>
    </nav>
  );
};
