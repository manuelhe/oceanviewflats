<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Support;

/**
 * Authoritative URL builder for public customer-facing static pages (/registry and /guide).
 *
 * Enforces SSG static page localization routes:
 * - English: /registry/index.html and /guide/index.html
 * - Spanish: /registry/es.html and /guide/es.html
 * - Other languages: /registry/{lang}.html and /guide/{lang}.html
 *
 * Static page routes handle language resolution; query parameters are never used
 * for language detection, but are preserved for reservation context (e.g. code, property, dates).
 */
class PublicUrlBuilder
{
    private readonly string $baseUrl;

    public function __construct(string $publicSiteUrl = 'https://oceanviewflats.com')
    {
        $this->baseUrl = rtrim($publicSiteUrl, '/');
    }

    /**
     * Builds the localized URL for the public guest registry form.
     *
     * @param string $lang Language code ('en', 'es', etc.)
     * @param array<string, scalar|null> $params Query parameters (e.g. property, check_in, check_out, code)
     */
    public function buildRegistryUrl(string $lang = 'en', array $params = []): string
    {
        $normalizedLang = strtolower(trim($lang));
        $file = ($normalizedLang === 'en' || $normalizedLang === '') ? 'index.html' : "{$normalizedLang}.html";

        // Query parameters must never contain 'lang' since language is statically routed
        unset($params['lang']);

        $filteredParams = array_filter(
            $params,
            static fn (mixed $val): bool => $val !== null && $val !== ''
        );

        $query = http_build_query($filteredParams);

        return $this->baseUrl . '/registry/' . $file . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Builds the localized URL for the public guest welcome guide.
     *
     * @param string $lang Language code ('en', 'es', etc.)
     * @param string|null $code Reservation UID or access code
     */
    public function buildGuideUrl(string $lang = 'en', ?string $code = null): string
    {
        $normalizedLang = strtolower(trim($lang));
        $file = ($normalizedLang === 'en' || $normalizedLang === '') ? 'index.html' : "{$normalizedLang}.html";

        $url = $this->baseUrl . '/guide/' . $file;

        if ($code !== null && trim($code) !== '') {
            $url .= '?code=' . urlencode(trim($code));
        }

        return $url;
    }
}
