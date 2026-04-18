import React from 'react';
import { Globe, ChevronDown } from 'lucide-react';
import { Lang } from '../types';

interface LanguageDropdownProps {
  currentLang: Lang;
  isScrolled: boolean;
}

const LANGUAGES: { code: Lang; label: string; link: string }[] = [
  { code: 'en', label: 'English', link: 'index.html' },
  { code: 'es', label: 'Español', link: 'es.html' },
  { code: 'fr', label: 'Français', link: 'fr.html' },
  { code: 'it', label: 'Italiano', link: 'it.html' },
  { code: 'de', label: 'Deutsch', link: 'de.html' },
  { code: 'ja', label: '日本語', link: 'ja.html' }
];

export const LanguageDropdown = ({ currentLang, isScrolled }: LanguageDropdownProps) => {
  const currentLangObj = LANGUAGES.find(l => l.code === currentLang) || LANGUAGES[0];
  const otherLangs = LANGUAGES.filter(l => l.code !== currentLang);

  return (
    <div className="relative group cursor-pointer" id="lang-toggle-group">
      <div 
        className={`flex items-center space-x-1 px-3 py-1.5 rounded-full text-sm font-medium transition-colors ${
          isScrolled ? 'bg-slate-100 hover:bg-slate-200 text-slate-700' : 'bg-white/20 hover:bg-white/30 text-white backdrop-blur-sm'
        }`}
        id="lang-toggle"
      >
        <Globe className="w-4 h-4" />
        <span>{currentLangObj.label}</span>
        <ChevronDown className="w-3 h-3 ml-1" />
      </div>
      
      {/* Dropdown Menu */}
      <div className="absolute right-0 top-full pt-2 w-32 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
        <div className="bg-white rounded-xl shadow-lg border border-slate-100">
          <ul className="py-2">
            {otherLangs.map((lang) => (
              <li key={lang.code}>
                <a 
                  href={lang.link}
                  className="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-[#FF5A5F] transition-colors"
                >
                  {lang.label}
                </a>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
};
