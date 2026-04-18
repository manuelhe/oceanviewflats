import React, { useState, useEffect } from 'react';
import { AppProps } from '../types';
import { Navigation } from '../components/Navigation';
import { Footer } from '../components/Footer';

// Warm Theme Components
import { HeroWarm } from '../components/warm/HeroWarm';
import { QuickStatsWarm } from '../components/warm/QuickStatsWarm';
import { RomanticFamilySection } from '../components/warm/RomanticFamilySection';
import { AboutAmenitiesWarm } from '../components/warm/AboutAmenitiesWarm';
import { ReviewsSection } from '../components/warm/ReviewsSection';
import { BookingSectionWarm } from '../components/warm/BookingSectionWarm';

export default function Oceanview1606({ lang, assetPrefix = './' }: AppProps) {
  const [isScrolled, setIsScrolled] = useState(false);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    const handleScroll = () => setIsScrolled(window.scrollY > 50);
    window.addEventListener('scroll', handleScroll);
    return () => window.removeEventListener('scroll', handleScroll);
  }, []);

  return (
    <div className="min-h-screen bg-stone-50 font-sans text-stone-800 scroll-smooth">
      <Navigation isScrolled={isScrolled} lang={lang} />
      <main>
        <HeroWarm lang={lang} assetPrefix={assetPrefix} />
        <QuickStatsWarm lang={lang} />
        <RomanticFamilySection lang={lang} assetPrefix={assetPrefix} />
        <AboutAmenitiesWarm lang={lang} assetPrefix={assetPrefix} />
        <ReviewsSection lang={lang} />
        <BookingSectionWarm lang={lang} />
      </main>
      <Footer lang={lang} />
    </div>
  );
}
