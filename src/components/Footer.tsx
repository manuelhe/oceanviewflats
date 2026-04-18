import React from 'react';
import { Waves } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';

export const Footer = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <footer className="bg-slate-900 text-slate-400 py-10 px-6 text-center border-t border-slate-800">
      <div className="max-w-4xl mx-auto flex flex-col items-center">
        <Waves className="w-8 h-8 text-slate-600 mb-4" />
        <p className="mb-2 font-medium">{t.footer}</p>
        <p className="text-sm">© {new Date().getFullYear()} {t.footerRights}</p>
      </div>
    </footer>
  );
};
