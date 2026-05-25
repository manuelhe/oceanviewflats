import React from 'react';
import { Users, Bed, Bath, Waves } from 'lucide-react';
import { Lang } from '../../types';
import { dict } from '../../i18n/dict';

export const QuickStatsWarm = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <div className="bg-white border-b border-stone-200 sticky top-[58px] md:top-[72px] z-40 block transition-all duration-300">
      <div className="max-w-4xl mx-auto px-4 md:px-6 py-3 md:py-4 flex justify-between items-center text-stone-600 font-medium overflow-x-auto whitespace-nowrap scrollbar-none gap-6 md:gap-0">
        <div className="flex items-center space-x-2 shrink-0">
          <Users className="w-4 h-4 md:w-5 md:h-5 text-rose-600" />
          <span className="text-xs md:text-sm">{t.statsGuests}</span>
        </div>
        <div className="w-px h-5 md:h-6 bg-stone-200 shrink-0"></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Bed className="w-4 h-4 md:w-5 md:h-5 text-rose-600" />
          <span className="text-xs md:text-sm">{t.stats1606Beds}</span>
        </div>
        <div className="w-px h-5 md:h-6 bg-stone-200 shrink-0"></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Bath className="w-4 h-4 md:w-5 md:h-5 text-rose-600" />
          <span className="text-xs md:text-sm">{t.statsBaths}</span>
        </div>
        <div className="w-px h-5 md:h-6 bg-stone-200 shrink-0"></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Waves className="w-4 h-4 md:w-5 md:h-5 text-rose-600" />
          <span className="text-xs md:text-sm">{t.amOcean}</span>
        </div>
      </div>
    </div>
  );
};
