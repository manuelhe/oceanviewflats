import React, { useState, useMemo } from 'react';
import { ExternalLink, Info } from 'lucide-react';
import { Lang } from '../types';
import { dict } from '../i18n/dict';
import { AIRBNB_URLS } from '../constants/config';
import { CalendarWidget } from './CalendarWidget';
import { formatDate } from '../utils/date';
import { THEMES } from '../constants/theme';

interface BookingSectionProps {
  lang: Lang;
  propertyId?: '1707' | '1606';
}

export const BookingSection = ({ lang, propertyId = '1707' }: BookingSectionProps) => {
  const t = dict[lang];
  const theme = THEMES[propertyId];
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
      const url = new URL(AIRBNB_URLS[propertyId]);
      url.searchParams.set('check_in', formatDate(checkIn));
      url.searchParams.set('check_out', formatDate(checkOut));
      return url.toString();
    }
    return AIRBNB_URLS[propertyId];
  }, [checkIn, checkOut, propertyId]);

  const secBgClass = propertyId === '1707' ? 'bg-slate-100 border-t border-slate-200' : 'bg-stone-100 border-t border-stone-200';
  const cardBorderClass = propertyId === '1707' ? 'border border-slate-200' : 'border border-stone-200';
  const leftColBgClass = propertyId === '1707' ? 'border-b md:border-b-0 md:border-r border-slate-200 bg-slate-50/50' : 'border-b md:border-b-0 md:border-r border-stone-200 bg-stone-50/50';
  const detailTitleClass = propertyId === '1707' ? 'text-slate-900' : 'text-stone-900';
  const detailSubClass = propertyId === '1707' ? 'text-slate-500' : 'text-stone-500';
  const inputContainerBorderClass = propertyId === '1707' ? 'border-slate-200' : 'border-stone-200';
  const inputLabelClass = propertyId === '1707' ? 'text-slate-400' : 'text-stone-400';
  const inputValTextSelectedClass = propertyId === '1707' ? 'text-slate-900' : 'text-stone-900';
  const inputValTextPlaceholderClass = propertyId === '1707' ? 'text-slate-300' : 'text-stone-300';
  
  const bookBtnClass = propertyId === '1707' 
    ? 'bg-[#FF5A5F] hover:bg-[#FF424D]' 
    : 'bg-rose-600 hover:bg-rose-700';

  const priceCardBgClass = propertyId === '1707' ? 'bg-slate-50 border border-slate-200' : 'bg-stone-50 border border-stone-200';
  const priceCardHeaderClass = propertyId === '1707' ? 'text-slate-800' : 'text-stone-800';
  const priceCardLabelClass = propertyId === '1707' ? 'text-slate-600' : 'text-stone-600';
  const priceCardValClass = propertyId === '1707' ? 'text-slate-900' : 'text-stone-900';
  const priceCardDividerClass = propertyId === '1707' ? 'border-slate-200' : 'border-stone-200';
  const priceCardTotalLabelClass = propertyId === '1707' ? 'text-slate-800' : 'text-stone-800';
  const priceCardTotalCOPClass = propertyId === '1707' ? 'text-slate-900' : 'text-stone-900';
  const priceCardTotalUSDLabelClass = propertyId === '1707' ? 'text-slate-500' : 'text-stone-500';
  const priceCardTooltipClass = propertyId === '1707' ? 'text-slate-400 hover:text-slate-600' : 'text-stone-400 hover:text-stone-600';

  const formTitleClass = propertyId === '1707' ? 'text-slate-800' : 'text-stone-800';
  const formFieldLabelClass = propertyId === '1707' ? 'text-slate-500' : 'text-stone-500';
  const formFieldInputClass = propertyId === '1707' 
    ? 'border-slate-200 text-slate-800 focus:border-[#FF5A5F]' 
    : 'border-stone-200 text-stone-800 focus:border-rose-600';

  const formCaptchaBoxBgClass = propertyId === '1707' ? 'bg-slate-50 border border-slate-200' : 'bg-stone-50 border border-stone-200';
  const formCaptchaLabelClass = propertyId === '1707' ? 'text-slate-500' : 'text-stone-500';
  const formCaptchaInputClass = propertyId === '1707' 
    ? 'border-slate-200 text-slate-800 focus:border-[#FF5A5F]' 
    : 'border-stone-200 text-stone-800 focus:border-rose-600';
  const formCaptchaHelpClass = propertyId === '1707' ? 'text-slate-400' : 'text-stone-400';

  return (
    <section id="booking" className={`py-24 px-6 ${secBgClass}`}>
      <div className="max-w-5xl mx-auto">
        <div className={`bg-white rounded-3xl shadow-xl overflow-hidden ${cardBorderClass}`}>
          <div className="grid md:grid-cols-2">
            {/* Left: Calendar */}
            <div className={`p-8 md:p-10 ${leftColBgClass}`}>
              <CalendarWidget 
                lang={lang} 
                checkIn={checkIn} 
                checkOut={checkOut} 
                onSelectDate={handleSelectDate} 
                propertyId={propertyId}
              />
            </div>
            
            {/* Right: Booking Details */}
            <div className="p-8 md:p-10 flex flex-col justify-center">
              <h3 className={`text-2xl md:text-3xl font-bold mb-3 ${detailTitleClass}`}>{t.calendarTitle}</h3>
              <p className={`${detailSubClass} mb-8 leading-relaxed`}>{t.calendarSubtitle}</p>
              
              <div className="flex space-x-4 mb-8">
                <div className={`flex-1 bg-white p-4 rounded-2xl border ${inputContainerBorderClass} shadow-sm`}>
                  <p className={`text-xs ${inputLabelClass} uppercase font-bold tracking-wider mb-1`}>{t.checkIn}</p>
                  <p 
                    id="check-in-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkIn ? inputValTextSelectedClass : inputValTextPlaceholderClass}`}
                  >
                    {checkIn ? formatDate(checkIn) : t.addDate}
                  </p>
                </div>
                <div className={`flex-1 bg-white p-4 rounded-2xl border ${inputContainerBorderClass} shadow-sm`}>
                  <p className={`text-xs ${inputLabelClass} uppercase font-bold tracking-wider mb-1`}>{t.checkOut}</p>
                  <p 
                    id="check-out-display" 
                    data-text-add-date={t.addDate}
                    className={`font-semibold text-lg ${checkOut ? inputValTextSelectedClass : inputValTextPlaceholderClass}`}
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
                className={`w-full flex items-center justify-center space-x-2 ${bookBtnClass} text-white py-4 rounded-xl font-bold text-lg transition-transform hover:scale-[1.02] shadow-lg mb-4`}
                data-text-ready={t.bookBtnReady}
                data-text-default={t.bookBtn}
                data-airbnb-url={AIRBNB_URLS[propertyId]}
              >
                <span id="btn-book-text">{checkIn && checkOut ? t.bookBtnReady : t.bookBtn}</span>
                <ExternalLink className="w-5 h-5" />
              </a>

              {/* Dynamic Price Breakdown Card */}
              <div id="price-breakdown-card" className={`hidden ${priceCardBgClass} p-6 rounded-2xl mb-6 transition-all duration-300`}>
                <h4 className={`font-bold ${priceCardHeaderClass} text-sm mb-4 uppercase tracking-wider`}>{t.dbPriceBreakdown}</h4>
                <div className="space-y-3 text-sm">
                  <div className="flex justify-between text-slate-600">
                    <span id="rate-breakdown-label">{t.dbAccommodation}</span>
                    <span id="rate-breakdown-value" className={`font-semibold ${priceCardValClass}`} data-nights-format={t.dbNightsFormat}>0 COP</span>
                  </div>
                  <div className="flex justify-between text-slate-600">
                    <span id="cleaning-fee-label">{t.dbCleaning}</span>
                    <span id="cleaning-fee-value" className={`font-semibold ${priceCardValClass}`}>0 COP</span>
                  </div>
                  <div className="flex justify-between text-slate-600">
                    <span id="resort-fee-label">{t.dbResort}</span>
                    <span id="resort-fee-value" className={`font-semibold ${priceCardValClass}`}>0 COP</span>
                  </div>
                  <hr className={`${priceCardDividerClass} my-2`} />
                  <div className="flex justify-between items-baseline">
                    <span className={`font-bold ${priceCardTotalLabelClass}`}>{t.dbTotal}</span>
                    <div className="text-right">
                      <div id="total-cop-value" className={`font-extrabold text-xl ${priceCardTotalCOPClass}`}>0 COP</div>
                      <div id="converted-currency-box" className={`hidden text-xs ${priceCardTotalUSDLabelClass} mt-1`}>
                        {t.dbEst} <span id="converted-currency-value">0 USD</span>
                        <span id="currency-tooltip-trigger" className={`inline-block ml-1 cursor-help ${priceCardTooltipClass}`} title={t.dbDisclaimer}>ⓘ</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Direct Booking Inquiry Form */}
              <form id="direct-booking-form" className="hidden space-y-4 mb-6 transition-all duration-300">
                <h4 className={`font-bold ${formTitleClass} text-sm uppercase tracking-wider`}>{t.dbFormTitle}</h4>
                <input type="hidden" name="property_id" id="form-property-id" value={propertyId} />
                <input type="hidden" name="check_in" id="form-check-in-date" />
                <input type="hidden" name="check_out" id="form-check-out-date" />
                <input type="hidden" name="total_price_cop" id="form-total-price-cop" />
                <input type="hidden" name="lang" value={lang} />

                <div>
                  <label className={`block text-xs font-bold ${formFieldLabelClass} uppercase tracking-wide mb-1`}>{t.dbName}</label>
                  <input type="text" name="guest_name" id="booking-guest-name" required className={`w-full p-3 rounded-xl border ${formFieldInputClass} text-sm focus:outline-none`} placeholder="John Doe" />
                </div>
                
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className={`block text-xs font-bold ${formFieldLabelClass} uppercase tracking-wide mb-1`}>{t.dbEmail}</label>
                    <input type="email" name="guest_email" id="booking-guest-email" required className={`w-full p-3 rounded-xl border ${formFieldInputClass} text-sm focus:outline-none`} placeholder="john@example.com" />
                  </div>
                  <div>
                    <label className={`block text-xs font-bold ${formFieldLabelClass} uppercase tracking-wide mb-1`}>{t.dbPhone}</label>
                    <input type="tel" name="guest_phone" id="booking-guest-phone" required className={`w-full p-3 rounded-xl border ${formFieldInputClass} text-sm focus:outline-none`} placeholder="+1 (123) 456-7890" />
                  </div>
                </div>

                <div className={`${formCaptchaBoxBgClass} p-4 rounded-xl grid grid-cols-2 gap-4 items-center`}>
                  <div>
                    <label id="booking-captcha-label" className={`block text-xs font-bold ${formCaptchaLabelClass} uppercase tracking-wide mb-1`}>{t.dbVerify}</label>
                    <input type="text" id="booking-captcha-response" required className={`w-full p-2 rounded-lg border ${formCaptchaInputClass} text-sm focus:outline-none`} placeholder="..." />
                    <input type="hidden" id="booking-captcha-challenge" name="captcha_challenge" />
                  </div>
                  <div className={`text-xs ${formCaptchaHelpClass} leading-tight`}>
                    {t.dbVerifyHelp}
                  </div>
                </div>

                <div id="booking-form-message" className="hidden"></div>

                <button 
                  type="submit" 
                  id="btn-direct-submit"
                  className="w-full flex items-center justify-center space-x-2 bg-emerald-600 hover:bg-emerald-700 text-white py-4 rounded-xl font-bold text-lg transition-transform hover:scale-[1.01] shadow-lg"
                >
                  <span id="btn-direct-submit-text">{t.dbSubmit}</span>
                </button>
              </form>

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
