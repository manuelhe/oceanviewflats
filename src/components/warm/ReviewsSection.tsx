import React from 'react';
import { Star, Quote } from 'lucide-react';
import { Lang } from '../../types';
import { dict } from '../../i18n/dict';

export const ReviewsSection = ({ lang }: { lang: Lang }) => {
  const t = dict[lang];

  return (
    <section className="py-24 px-6 bg-stone-50 border-t border-stone-200">
      <div className="max-w-6xl mx-auto">
        <h2 className="text-3xl font-bold mb-12 text-center text-stone-900">{t.reviewsTitle}</h2>
        <div className="grid md:grid-cols-2 gap-8">
          
          <div className="bg-white p-8 rounded-2xl shadow-sm border border-stone-100 flex flex-col relative">
            <Quote className="absolute top-6 right-6 w-8 h-8 text-stone-100" />
            <div className="flex text-amber-400 mb-4">
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
            </div>
            <p className="text-stone-600 mb-6 flex-grow italic leading-relaxed">
              {t.review1Text}
            </p>
            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 bg-rose-100 rounded-full flex items-center justify-center text-rose-600 font-bold">
                {t.review1Author.charAt(0)}
              </div>
              <span className="font-semibold text-stone-900">{t.review1Author}</span>
            </div>
          </div>

          <div className="bg-white p-8 rounded-2xl shadow-sm border border-stone-100 flex flex-col relative">
            <Quote className="absolute top-6 right-6 w-8 h-8 text-stone-100" />
            <div className="flex text-amber-400 mb-4">
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
              <Star className="w-5 h-5 fill-current" />
            </div>
            <p className="text-stone-600 mb-6 flex-grow italic leading-relaxed">
              {t.review2Text}
            </p>
            <div className="flex items-center space-x-3">
              <div className="w-10 h-10 bg-rose-100 rounded-full flex items-center justify-center text-rose-600 font-bold">
                {t.review2Author.charAt(0)}
              </div>
              <span className="font-semibold text-stone-900">{t.review2Author}</span>
            </div>
          </div>

        </div>
      </div>
    </section>
  );
};
