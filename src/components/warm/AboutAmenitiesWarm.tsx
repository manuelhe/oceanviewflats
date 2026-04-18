import React from 'react';
import { Wifi, Wind, Waves, Coffee, Globe, Car, Lock, MapPin } from 'lucide-react';
import { Lang } from '../../types';
import { dict } from '../../i18n/dict';
import { IMAGES } from '../../constants/config';

export const AboutAmenitiesWarm = ({ lang, assetPrefix = './' }: { lang: Lang, assetPrefix?: string }) => {
  const t = dict[lang];

  return (
    <section className="py-24 px-6 max-w-6xl mx-auto">
      <div className="grid md:grid-cols-2 gap-16">
        <div>
          <h2 className="text-3xl font-bold mb-6 text-stone-900">{t.aboutTitle}</h2>
          <div className="prose prose-lg text-stone-600 mb-8">
            <p className="mb-4 leading-relaxed">{t.aboutText1}</p>
            <p className="leading-relaxed">{t.aboutText2}</p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <img src={`${assetPrefix}${IMAGES['1606'].bedroom}`} alt={t.aboutBedroomAlt} className="rounded-xl shadow-md h-48 w-full object-cover" />
            <img src={`${assetPrefix}${IMAGES['1606'].pool}`} alt={t.aboutPoolAlt} className="rounded-xl shadow-md h-48 w-full object-cover" />
          </div>
        </div>
        <div>
          <h2 className="text-3xl font-bold mb-8 text-stone-900">{t.amenitiesTitle}</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
            {[
              { icon: <Wifi className="w-6 h-6" />, text: t.amWifi },
              { icon: <Wind className="w-6 h-6" />, text: t.amAC },
              { icon: <Waves className="w-6 h-6" />, text: t.amBeach },
              { icon: <Coffee className="w-6 h-6" />, text: t.amKitchen },
              { icon: <Globe className="w-6 h-6" />, text: t.amPool },
              { icon: <Car className="w-6 h-6" />, text: t.amParking },
              { icon: <Lock className="w-6 h-6" />, text: t.amSelfCheckIn },
              { icon: <MapPin className="w-6 h-6" />, text: t.amOcean },
            ].map((item, index) => (
              <div key={index} className="flex items-center space-x-4 p-4 bg-white rounded-xl shadow-sm border border-stone-100 hover:shadow-md transition-shadow">
                <div className="text-rose-600 bg-rose-50 p-2 rounded-lg">{item.icon}</div>
                <span className="font-semibold text-stone-700">{item.text}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
};
