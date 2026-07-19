import React from 'react';
import { Wifi, Wind, Waves, Coffee, Globe, Car, Lock, MapPin } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { IMAGES } from '../constants/config';
import { THEMES } from '../constants/theme';

export const AboutAmenities = ({ lang, propertyId, assetPrefix = './' }: { lang: Lang, propertyId: '1707' | '1606', assetPrefix?: string }) => {
  const t = dict[lang];
  const theme = THEMES[propertyId];

  const themeAccentClass = propertyId === '1707' 
    ? 'text-[#FF5A5F] bg-red-50' 
    : 'text-rose-600 bg-rose-50';

  const hostAccentClass = propertyId === '1707'
    ? 'text-[#FF5A5F]'
    : 'text-rose-400';

  const hostBorderClass = propertyId === '1707'
    ? 'border-slate-700'
    : 'border-stone-700';

  const hostBgClass = propertyId === '1707'
    ? 'bg-slate-900 font-sans'
    : 'bg-stone-900 font-sans';

  const hostNameClass = propertyId === '1707'
    ? 'text-white'
    : 'text-stone-50';

  return (
    <section className="py-24 px-6 max-w-6xl mx-auto">
      <div className="grid md:grid-cols-2 gap-16">
        <div>
          <h2 className={`text-3xl font-bold mb-6 ${theme.headingColor}`}>{t.aboutTitle}</h2>
          <div className={`prose prose-lg ${theme.textColor} mb-8`}>
            <p className="mb-4 leading-relaxed">{t.aboutText1}</p>
            <p className="leading-relaxed">{t.aboutText2}</p>
          </div>
          <div className="grid grid-cols-2 gap-4">
            <img src={`${assetPrefix}${IMAGES[theme.imagesKey].bedroom}`} alt={t.aboutBedroomAlt} className="rounded-xl shadow-md h-48 w-full object-cover" loading="lazy" />
            <img src={`${assetPrefix}${IMAGES[theme.imagesKey].pool}`} alt={t.aboutPoolAlt} className="rounded-xl shadow-md h-48 w-full object-cover" loading="lazy" />
          </div>
        </div>
        <div>
          <h2 className={`text-3xl font-bold mb-8 ${theme.headingColor}`}>{t.amenitiesTitle}</h2>
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
              <div key={index} className={`flex items-center space-x-4 p-4 bg-white rounded-xl shadow-sm border ${theme.borderColor} hover:shadow-md transition-shadow`}>
                <div className={`${themeAccentClass} p-2 rounded-lg`}>{item.icon}</div>
                <span className={`font-semibold ${theme.textColor}`}>{item.text}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <div className={`mt-20 p-8 md:p-12 ${hostBgClass} rounded-3xl text-white flex flex-col md:flex-row items-center gap-10`}>
        <div className={`w-32 h-32 md:w-48 md:h-48 rounded-full overflow-hidden flex-shrink-0 border-4 ${hostBorderClass}`}>
           <img 
              src={`${assetPrefix}${IMAGES['general'].host}`} 
              alt={t.hostName} 
              className="w-full h-full object-cover"
              loading="lazy"
           />
        </div>
        <div>
           <h2 className={`text-sm font-bold uppercase tracking-widest ${hostAccentClass} mb-2`}>{t.hostTitle}</h2>
           <h3 className={`text-3xl font-bold mb-4 ${hostNameClass}`}>{t.hostName}</h3>
           <p className="text-lg text-slate-300 leading-relaxed max-w-2xl">
              {t.hostBio}
           </p>
        </div>
      </div>
    </section>
  );
};
