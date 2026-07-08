import React from 'react';
import { Star, Quote } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';

export const ReviewsSection1707 = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <section className="py-24 px-6 bg-slate-50 border-t border-slate-200/60 relative overflow-hidden">
      {/* Decorative background glow */}
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-[#FF5A5F]/5 rounded-full blur-3xl pointer-events-none" />

      <div className="max-w-4xl mx-auto relative z-10">
        <h2 className="text-3xl font-bold mb-12 text-center text-slate-900 tracking-tight">{t.reviewsTitle}</h2>
        
        <div className="bg-white/80 backdrop-blur-md p-10 md:p-14 rounded-3xl shadow-xl shadow-slate-100/50 border border-slate-100 flex flex-col items-center text-center relative max-w-3xl mx-auto">
          <Quote className="absolute top-8 left-10 w-16 h-16 text-slate-100/80 -scale-x-100 pointer-events-none select-none" />
          
          <div className="flex text-amber-400 mb-6 space-x-1">
            <Star className="w-6 h-6 fill-current" />
            <Star className="w-6 h-6 fill-current" />
            <Star className="w-6 h-6 fill-current" />
            <Star className="w-6 h-6 fill-current" />
            <Star className="w-6 h-6 fill-current" />
          </div>
          
          <p className="text-lg md:text-xl text-slate-600 mb-8 font-light italic leading-relaxed relative z-10">
            {t.review1707Text}
          </p>
          
          <div className="flex flex-col items-center">
            <div className="w-14 h-14 bg-slate-100 text-slate-700 rounded-full flex items-center justify-center font-bold text-lg mb-3 shadow-inner border border-slate-200">
              {t.review1707Author ? t.review1707Author.charAt(0) : 'D'}
            </div>
            <span className="font-bold text-slate-800 tracking-wide text-base">{t.review1707Author}</span>
            <span className="text-xs text-slate-400 mt-1 uppercase tracking-wider font-semibold">Verified Guest</span>
          </div>
        </div>
      </div>
    </section>
  );
};
