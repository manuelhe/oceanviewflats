import React from 'react';
import { Users, Bed, Bath, Waves } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';

export const QuickStats = ({ lang, propertyId = '1707' }: { lang: Lang, propertyId?: '1707' | '1606' }) => {
  const t = dict[lang];

  const secClass = propertyId === '1707'
    ? 'bg-white border-b border-slate-200 text-slate-600'
    : 'bg-white border-b border-stone-200 text-stone-600';

  const iconClass = propertyId === '1707' ? 'text-[#FF5A5F]' : 'text-rose-600';
  const dividerClass = propertyId === '1707' ? 'bg-slate-200' : 'bg-stone-200';

  const bedText = propertyId === '1707' ? t.statsBeds : t.stats1606Beds;

  return (
    <div className={`${secClass} sticky top-[58px] md:top-[72px] z-40 block transition-all duration-300`}>
      <div className="max-w-4xl mx-auto px-4 md:px-6 py-3 md:py-4 flex justify-between items-center font-medium overflow-x-auto whitespace-nowrap scrollbar-none gap-6 md:gap-0">
        <div className="flex items-center space-x-2 shrink-0">
          <Users className={`w-4 h-4 md:w-5 md:h-5 ${iconClass}`} />
          <span className="text-xs md:text-sm">{t.statsGuests}</span>
        </div>
        <div className={`w-px h-5 md:h-6 ${dividerClass} shrink-0`}></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Bed className={`w-4 h-4 md:w-5 md:h-5 ${iconClass}`} />
          <span className="text-xs md:text-sm">{bedText}</span>
        </div>
        <div className={`w-px h-5 md:h-6 ${dividerClass} shrink-0`}></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Bath className={`w-4 h-4 md:w-5 md:h-5 ${iconClass}`} />
          <span className="text-xs md:text-sm">{t.statsBaths}</span>
        </div>
        <div className={`w-px h-5 md:h-6 ${dividerClass} shrink-0`}></div>
        <div className="flex items-center space-x-2 shrink-0">
          <Waves className={`w-4 h-4 md:w-5 md:h-5 ${iconClass}`} />
          <span className="text-xs md:text-sm">{t.amOcean}</span>
        </div>
      </div>
    </div>
  );
};
