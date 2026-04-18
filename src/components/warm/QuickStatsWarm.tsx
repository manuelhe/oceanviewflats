import React from 'react';
import { Users, Bed, Bath, Waves } from 'lucide-react';
import { Lang } from '../../types';
import { dict } from '../../i18n/dict';

export const QuickStatsWarm = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <div className="bg-white border-b border-stone-200 sticky top-0 z-40 hidden md:block">
      <div className="max-w-4xl mx-auto px-6 py-4 flex justify-between items-center text-stone-600 font-medium">
        <div className="flex items-center space-x-2"><Users className="w-5 h-5 text-rose-600" /><span>{t.statsGuests}</span></div>
        <div className="w-px h-6 bg-stone-200"></div>
        <div className="flex items-center space-x-2"><Bed className="w-5 h-5 text-rose-600" /><span>{t.stats1606Beds}</span></div>
        <div className="w-px h-6 bg-stone-200"></div>
        <div className="flex items-center space-x-2"><Bath className="w-5 h-5 text-rose-600" /><span>{t.statsBaths}</span></div>
        <div className="w-px h-6 bg-stone-200"></div>
        <div className="flex items-center space-x-2"><Waves className="w-5 h-5 text-rose-600" /><span>{t.amOcean}</span></div>
      </div>
    </div>
  );
};
