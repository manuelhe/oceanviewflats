import { Lang } from '../types';

export interface PropertyTheme {
  accentColor: string;      // e.g., '#FF5A5F' vs 'rose-600'
  accentBg: string;          // e.g., 'bg-red-50' vs 'bg-rose-50'
  textColor: string;         // e.g., 'text-slate-800' vs 'text-stone-800'
  headingColor: string;      // e.g., 'text-slate-900' vs 'text-stone-900'
  borderColor: string;       // e.g., 'border-slate-100' vs 'border-stone-100'
  borderFocusColor: string;  // e.g., 'focus:border-slate-400' vs 'focus:border-stone-400'
  bgHover: string;           // e.g., 'hover:bg-slate-100' vs 'hover:bg-stone-100'
  imagesKey: '1707' | '1606';
}

export const THEMES: Record<'1707' | '1606', PropertyTheme> = {
  '1707': {
    accentColor: '#FF5A5F',
    accentBg: 'bg-red-50',
    textColor: 'text-slate-800',
    headingColor: 'text-slate-900',
    borderColor: 'border-slate-100',
    borderFocusColor: 'focus:border-slate-400',
    bgHover: 'hover:bg-slate-100',
    imagesKey: '1707'
  },
  '1606': {
    accentColor: 'rose-600',
    accentBg: 'bg-rose-50',
    textColor: 'text-stone-800',
    headingColor: 'text-stone-900',
    borderColor: 'border-stone-100',
    borderFocusColor: 'focus:border-stone-400',
    bgHover: 'hover:bg-stone-100',
    imagesKey: '1606'
  }
};
