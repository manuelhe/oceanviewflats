import React, { useState, useEffect } from 'react';
import { AppProps } from '../types';
import { Navigation } from '../components/Navigation';
import { Hero } from '../components/Hero';
import { QuickStats } from '../components/QuickStats';
import { ImageGallery } from '../components/ImageGallery';
import { NomadSection } from '../components/NomadSection';
import { AboutAmenities } from '../components/AboutAmenities';
import { BookingSection } from '../components/BookingSection';
import { Footer } from '../components/Footer';

export default function Oceanview1707({ lang, assetPrefix = './' }: AppProps) {
  const [isScrolled, setIsScrolled] = useState(false);

  // We'll keep the scroll effect but in static HTML it will only work if we include this script
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handleScroll = () => setIsScrolled(window.scrollY > 50);
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  return (
    <div className="min-h-screen bg-slate-50 font-sans text-slate-800 scroll-smooth">
      <Navigation isScrolled={isScrolled} lang={lang} assetPrefix={assetPrefix} />
      <main>
        <Hero lang={lang} assetPrefix={assetPrefix} />
        <QuickStats lang={lang} />
        <ImageGallery lang={lang} property="1707" assetPrefix={assetPrefix} />
        <NomadSection lang={lang} assetPrefix={assetPrefix} />
        <AboutAmenities lang={lang} assetPrefix={assetPrefix} />
        <BookingSection lang={lang} />
      </main>
      <Footer lang={lang} />
    </div>
  );
}
