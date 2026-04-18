export type Lang = 'en' | 'es' | 'fr' | 'it' | 'de' | 'ja';

export interface AppProps {
  lang: Lang;
  assetPrefix?: string;
}

export interface CalendarWidgetProps {
  lang: Lang;
  checkIn: Date | null;
  checkOut: Date | null;
  onSelectDate: (date: Date) => void;
}
