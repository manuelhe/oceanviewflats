import React, { useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { CalendarWidgetProps } from '../types';

export const CalendarWidget = ({ lang, checkIn, checkOut, onSelectDate }: CalendarWidgetProps) => {
  const [viewDate, setViewDate] = useState(new Date());

  const daysInMonth = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 0).getDate();
  const firstDayOfMonth = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1).getDay();

  const monthNames = {
    en: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
    es: ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"],
    fr: ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"],
    it: ["Gennaio", "Febbraio", "Marzo", "Aprile", "Maggio", "Giugno", "Luglio", "Agosto", "Settembre", "Ottobre", "Novembre", "Dicembre"],
    de: ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"],
    ja: ["1月", "2月", "3月", "4月", "5月", "6月", "7月", "8月", "9月", "10月", "11月", "12月"]
  };
  const dayNames = {
    en: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"],
    es: ["Do", "Lu", "Ma", "Mi", "Ju", "Vi", "Sa"],
    fr: ["Di", "Lu", "Ma", "Me", "Je", "Vi", "Sa"],
    it: ["Do", "Lu", "Ma", "Me", "Gi", "Ve", "Sa"],
    de: ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"],
    ja: ["日", "月", "火", "水", "木", "金", "土"]
  };

  const nextMonth = () => {
    setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1));
  };

  const prevMonth = () => {
    setViewDate(new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1));
  };

  const days = [];
  for (let i = 0; i < firstDayOfMonth; i++) {
    days.push(<div key={`empty-${i}`} className="w-8 h-8 md:w-10 md:h-10"></div>);
  }

  const today = new Date();
  today.setHours(0,0,0,0);

  for (let i = 1; i <= daysInMonth; i++) {
    const thisDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), i);
    const isPast = thisDate < today;
    
    const isCheckIn = checkIn && thisDate.getTime() === checkIn.getTime();
    const isCheckOut = checkOut && thisDate.getTime() === checkOut.getTime();
    const isInRange = checkIn && checkOut && thisDate > checkIn && thisDate < checkOut;

    let bgClass = "bg-white hover:bg-slate-100 text-slate-700";
    if (isPast) {
      bgClass = "bg-transparent text-slate-300 cursor-not-allowed";
    } else if (isCheckIn || isCheckOut) {
      bgClass = "bg-[#FF5A5F] text-white";
    } else if (isInRange) {
      bgClass = "bg-[#FF5A5F]/10 text-[#FF5A5F]";
    }

    days.push(
      <button
        key={`day-${i}`}
        disabled={isPast}
        onClick={() => onSelectDate && onSelectDate(thisDate)}
        className={`calendar-day w-8 h-8 md:w-10 md:h-10 rounded-full flex items-center justify-center text-sm transition-all font-medium ${bgClass}`}
      >
        {i}
      </button>
    );
  }

  return (
    <div 
      className="w-full max-w-sm mx-auto select-none" 
      id="calendar-widget" 
      data-lang={lang} 
      data-month-names={JSON.stringify(monthNames)} 
      data-day-names={JSON.stringify(dayNames)}
    >
      <div className="flex items-center justify-between mb-4">
        <button 
          id="btn-prev-month"
          onClick={prevMonth}
          className="p-2 hover:bg-slate-100 rounded-full transition-colors"
        >
          <ChevronLeft className="w-5 h-5 text-slate-600" />
        </button>
        <div id="month-label" className="font-bold text-lg text-slate-800">
          {monthNames[lang][viewDate.getMonth()]} {viewDate.getFullYear()}
        </div>
        <button 
          id="btn-next-month"
          onClick={nextMonth}
          className="p-2 hover:bg-slate-100 rounded-full transition-colors"
        >
          <ChevronRight className="w-5 h-5 text-slate-600" />
        </button>
      </div>
      <div className="grid grid-cols-7 gap-1 mb-2">
        {dayNames[lang].map(day => (
          <div key={day} className="text-center text-xs font-bold text-slate-400 py-2">
            {day}
          </div>
        ))}
      </div>
      <div id="calendar-grid" className="grid grid-cols-7 gap-1 justify-items-center">
        {days}
      </div>
    </div>
  );
};
