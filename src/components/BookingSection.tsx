import React, { useState, useMemo } from 'react';
import { ExternalLink, Info } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { AIRBNB_URLS } from '../constants/config';
import { CalendarWidget } from './CalendarWidget';
import { formatDate } from '../utils/date';

interface BookingSectionProps {
  lang: Lang;
}

export const BookingSection = ({ lang }: BookingSectionProps) => {
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
      const url = new URL(AIRBNB_URLS['1707']);
      url.searchParams.set('check_in', formatDate(checkIn));
      url.searchParams.set('check_out', formatDate(checkOut));
      return url.toString();
    }
    return AIRBNB_URLS['1707'];
  }, [checkIn, checkOut]);

  return (
    <section id="booking" className="py-24 px-6 bg-slate-100 border-t border-slate-200">
      <div className="max-w-5xl mx-auto">
        <div className="bg-white rounded-3xl shadow-xl overflow-hidden border border-slate-200">
          <div className="grid md:grid-cols-2">
            {/* Left: Calendar */}
            <div className="p-8 md:p-10 border-b md:border-b-0 md:border-r border-slate-200 bg-slate-50/50">
              <CalendarWidget 
                lang={lang} 
                checkIn={checkIn} 
                checkOut={checkOut} 
                onSelectDate={handleSelectDate} 
              />
            </div>
            
            {/* Right: Booking Details */}
            <div className="p-8 md:p-10 flex flex-col justify-center">
              <h3 className="text-2xl md:text-3xl font-bold mb-3 text-slate-900">{t.calendarTitle}</h3>
              <p className="text-slate-500 mb-8 leading-relaxed">{t.calendarSubtitle}</p>
              
              <div className="flex space-x-4 mb-8">
                <div className="flex-1 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                  <p className="text-xs text-slate-400 uppercase font-bold tracking-wider mb-1">{t.checkIn}</p>
                  <p 
                    id="check-in-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkIn ? 'text-slate-900' : 'text-slate-300'}`}
                  >
                    {checkIn ? formatDate(checkIn) : t.addDate}
                  </p>
                </div>
                <div className="flex-1 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                  <p className="text-xs text-slate-400 uppercase font-bold tracking-wider mb-1">{t.checkOut}</p>
                  <p 
                    id="check-out-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkOut ? 'text-slate-900' : 'text-slate-300'}`}
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
                className="w-full flex items-center justify-center space-x-2 bg-[#FF5A5F] hover:bg-[#FF424D] text-white py-4 rounded-xl font-bold text-lg transition-transform hover:scale-[1.02] shadow-lg mb-6"
                data-text-ready={t.bookBtnReady}
                data-text-default={t.bookBtn}
                data-airbnb-url={AIRBNB_URLS['1707']}
              >
                <span id="btn-book-text">{checkIn && checkOut ? t.bookBtnReady : t.bookBtn}</span>
                <ExternalLink className="w-5 h-5" />
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
