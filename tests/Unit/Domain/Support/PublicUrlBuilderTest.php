<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Support;

use OceanViewFlats\Domain\Support\PublicUrlBuilder;
use PHPUnit\Framework\TestCase;

final class PublicUrlBuilderTest extends TestCase
{
    private PublicUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PublicUrlBuilder('https://oceanviewflats.com');
    }

    public function testBuildRegistryUrlEnglishUsesIndexHtml(): void
    {
        $url = $this->builder->buildRegistryUrl('en', [
            'property' => '1606',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'code' => 'res-101',
        ]);

        $this->assertSame(
            'https://oceanviewflats.com/registry/index.html?property=1606&check_in=2026-10-10&check_out=2026-10-15&code=res-101',
            $url
        );
    }

    public function testBuildRegistryUrlSpanishUsesEsHtml(): void
    {
        $url = $this->builder->buildRegistryUrl('es', [
            'property' => '1707',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'code' => 'res-202',
        ]);

        $this->assertSame(
            'https://oceanviewflats.com/registry/es.html?property=1707&check_in=2026-11-01&check_out=2026-11-05&code=res-202',
            $url
        );
    }

    public function testBuildRegistryUrlStripsLangQueryParameter(): void
    {
        $url = $this->builder->buildRegistryUrl('es', [
            'lang' => 'es',
            'code' => 'res-202',
        ]);

        $this->assertSame('https://oceanviewflats.com/registry/es.html?code=res-202', $url);
        $this->assertStringNotContainsString('lang=', $url);
    }

    public function testBuildRegistryUrlWithoutParams(): void
    {
        $url = $this->builder->buildRegistryUrl('en');
        $this->assertSame('https://oceanviewflats.com/registry/index.html', $url);
    }

    public function testBuildRegistryUrlSupportsOtherLanguages(): void
    {
        $url = $this->builder->buildRegistryUrl('fr', ['code' => 'res-303']);
        $this->assertSame('https://oceanviewflats.com/registry/fr.html?code=res-303', $url);

        $urlJa = $this->builder->buildRegistryUrl('ja', ['code' => 'res-ja']);
        $this->assertSame('https://oceanviewflats.com/registry/ja.html?code=res-ja', $urlJa);
    }

    public function testBuildGuideUrlEnglishUsesIndexHtml(): void
    {
        $url = $this->builder->buildGuideUrl('en', 'res-101');
        $this->assertSame('https://oceanviewflats.com/guide/index.html?code=res-101', $url);
    }

    public function testBuildGuideUrlSpanishUsesEsHtml(): void
    {
        $url = $this->builder->buildGuideUrl('es', 'res-202');
        $this->assertSame('https://oceanviewflats.com/guide/es.html?code=res-202', $url);
    }

    public function testBuildGuideUrlWithoutCode(): void
    {
        $urlEn = $this->builder->buildGuideUrl('en');
        $this->assertSame('https://oceanviewflats.com/guide/index.html', $urlEn);

        $urlEs = $this->builder->buildGuideUrl('es');
        $this->assertSame('https://oceanviewflats.com/guide/es.html', $urlEs);
    }

    public function testCustomBaseUrlTrailingSlashNormalized(): void
    {
        $custom = new PublicUrlBuilder('http://localhost:8080/');
        $url = $custom->buildGuideUrl('es', 'test-code');
        $this->assertSame('http://localhost:8080/guide/es.html?code=test-code', $url);
    }
}
