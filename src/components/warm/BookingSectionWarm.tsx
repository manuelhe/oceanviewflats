import React, { useState, useMemo } from 'react';
import { ExternalLink, Info } from 'lucide-react';
import { Lang } from '../../types';
import { dict } from '../../i18n/dict';
import { AIRBNB_URLS } from '../../constants/config';
import { CalendarWidgetWarm } from './CalendarWidgetWarm';
import { formatDate } from '../../utils/date';

interface BookingSectionProps {
  lang: Lang;
}

export const BookingSectionWarm = ({ lang }: BookingSectionProps) => {
  const t = dict[lang];
  const [checkIn, setCheckIn] = useState<Date | null>(null);
  const [checkOut, setCheckOut] = useState<Date | null>(null);

  const handleSelectDate = (date: Date) => {
    if (!checkIn || (checkIn && checkOut)) {
      setCheckIn(date);
      setCheckOut(null);
    } else if (date > checkIn) {
      setCheckOut(date);
    } else {
      setCheckIn(date);
      setCheckOut(null);
    }
  };

  const bookingUrl = useMemo(() => {
    if (checkIn && checkOut) {
      const url = new URL(AIRBNB_URLS['1606']);
      url.searchParams.set('check_in', formatDate(checkIn));
      url.searchParams.set('check_out', formatDate(checkOut));
      return url.toString();
    }
    return AIRBNB_URLS['1606'];
  }, [checkIn, checkOut]);

  return (
    <section id="booking" className="py-24 px-6 bg-stone-100 border-t border-stone-200">
      <div className="max-w-5xl mx-auto">
        <div className="bg-white rounded-3xl shadow-xl overflow-hidden border border-stone-200">
          <div className="grid md:grid-cols-2">
            {/* Left: Calendar */}
            <div className="p-8 md:p-10 border-b md:border-b-0 md:border-r border-stone-200 bg-stone-50/50">
              <CalendarWidgetWarm 
                lang={lang} 
                checkIn={checkIn} 
                checkOut={checkOut} 
                onSelectDate={handleSelectDate} 
              />
            </div>
            
            {/* Right: Booking Details */}
            <div className="p-8 md:p-10 flex flex-col justify-center">
              <h3 className="text-2xl md:text-3xl font-bold mb-3 text-stone-900">{t.calendarTitle}</h3>
              <p className="text-stone-500 mb-8 leading-relaxed">{t.calendarSubtitle}</p>
              
              <div className="flex space-x-4 mb-8">
                <div className="flex-1 bg-white p-4 rounded-2xl border border-stone-200 shadow-sm">
                  <p className="text-xs text-stone-400 uppercase font-bold tracking-wider mb-1">{t.checkIn}</p>
                  <p 
                    id="check-in-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkIn ? 'text-stone-900' : 'text-stone-300'}`}
                  >
                    {checkIn ? formatDate(checkIn) : t.addDate}
                  </p>
                </div>
                <div className="flex-1 bg-white p-4 rounded-2xl border border-stone-200 shadow-sm">
                  <p className="text-xs text-stone-400 uppercase font-bold tracking-wider mb-1">{t.checkOut}</p>
                  <p 
                    id="check-out-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkOut ? 'text-stone-900' : 'text-stone-300'}`}
                  >
                    {checkOut ? formatDate(checkOut) : t.addDate}
                  </p>
                </div>
              </div>

              <a 
                id="btn-book"
                href={bookingUrl} 
                target="_blank" 
                rel="noopener noreferrer" 
                className="w-full flex items-center justify-center space-x-2 bg-rose-600 hover:bg-[#FF424D] text-white py-4 rounded-xl font-bold text-lg transition-transform hover:scale-[1.02] shadow-lg mb-4"
                data-text-ready={t.bookBtnReady}
                data-text-default={t.bookBtn}
                data-airbnb-url={AIRBNB_URLS['1606']}
              >
                <span id="btn-book-text">{checkIn && checkOut ? t.bookBtnReady : t.bookBtn}</span>
                <ExternalLink className="w-5 h-5" />
              </a>

              <a
                id="whatsapp-booking-link"
                href="https://wa.me/573108155234"
                target="_blank"
                rel="noopener noreferrer"
                className="w-full flex items-center justify-center space-x-2 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-800 py-3 rounded-xl font-semibold transition-colors mb-6 text-sm"
              >
                <svg className="w-4 h-4 fill-current" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                  <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.514 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.724-1.458L0 24zm6.59-4.846c1.6.95 3.188 1.449 4.825 1.451 5.436 0 9.86-4.37 9.864-9.799.002-2.63-1.023-5.101-2.885-6.963C16.588 1.981 14.12 .956 11.497.956 6.064.956 1.64 5.326 1.636 10.757c-.001 1.705.452 3.369 1.312 4.83l-.974 3.559 3.655-.96L6.647 19.154z"/>
                </svg>
                <span>WhatsApp</span>
              </a>

              <div className="bg-amber-50 border border-amber-100 p-4 rounded-xl flex items-start space-x-3 text-amber-800 text-sm">
                 <Info className="w-5 h-5 flex-shrink-0 mt-0.5 text-amber-500" />
                 <div>
                   <p className="font-bold mb-1">{t.poolNoteTitle}</p>
                   <p className="leading-relaxed opacity-90">{t.poolNote}</p>
                 </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
};
