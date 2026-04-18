import React from 'react';
import { Users, Bed, Bath, Waves } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';

export const QuickStats = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <div className="bg-white border-b border-slate-200 sticky top-0 z-40 hidden md:block">
      <div className="max-w-4xl mx-auto px-6 py-4 flex justify-between items-center text-slate-600 font-medium">
        <div className="flex items-center space-x-2"><Users className="w-5 h-5 text-[#FF5A5F]" /><span>{t.statsGuests}</span></div>
        <div className="w-px h-6 bg-slate-200"></div>
        <div className="flex items-center space-x-2"><Bed className="w-5 h-5 text-[#FF5A5F]" /><span>{t.statsBeds}</span></div>
        <div className="w-px h-6 bg-slate-200"></div>
        <div className="flex items-center space-x-2"><Bath className="w-5 h-5 text-[#FF5A5F]" /><span>{t.statsBaths}</span></div>
        <div className="w-px h-6 bg-slate-200"></div>
        <div className="flex items-center space-x-2"><Waves className="w-5 h-5 text-[#FF5A5F]" /><span>{t.amOcean}</span></div>
      </div>
    </div>
  );
};
