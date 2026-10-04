<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Service;

use OceanViewFlats\Admin\Service\PublicUrlBuilder;
use PHPUnit\Framework\TestCase;

final class PublicUrlBuilderTest extends TestCase
{
    private PublicUrlBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PublicUrlBuilder('https://oceanviewflats.com');
    }

    public function testBuildRegistryUrlForEnglishUsesIndexHtml(): void
    {
        $url = $this->builder->buildRegistryUrl('en', [
            'property' => '1606',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'code' => 'res-101',
            'lang' => 'en',
        ]);

        $this->assertSame(
            'https://oceanviewflats.com/registry/index.html?property=1606&check_in=2026-10-10&check_out=2026-10-15&code=res-101',
            $url
        );
        $this->assertStringNotContainsString('lang=', $url);
    }

    public function testBuildRegistryUrlForSpanishUsesEsHtml(): void
    {
        $url = $this->builder->buildRegistryUrl('es', [
            'property' => '1707',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'code' => 'res-202',
            'lang' => 'es',
        ]);

        $this->assertSame(
            'https://oceanviewflats.com/registry/es.html?property=1707&check_in=2026-11-01&check_out=2026-11-05&code=res-202',
            $url
        );
        $this->assertStringNotContainsString('lang=', $url);
    }

    public function testBuildRegistryUrlDefaultLanguageAndEmptyParams(): void
    {
        $url = $this->builder->buildRegistryUrl('');
        $this->assertSame('https://oceanviewflats.com/registry/index.html', $url);
    }

    public function testBuildRegistryUrlSupportsOtherLanguages(): void
    {
        $url = $this->builder->buildRegistryUrl('fr', ['code' => 'res-303']);
        $this->assertSame('https://oceanviewflats.com/registry/fr.html?code=res-303', $url);
    }

    public function testBuildGuideUrlForEnglishUsesIndexHtml(): void
    {
        $url = $this->builder->buildGuideUrl('en', 'res-101');
        $this->assertSame('https://oceanviewflats.com/guide/index.html?code=res-101', $url);
    }

    public function testBuildGuideUrlForSpanishUsesEsHtml(): void
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
        $customBuilder = new PublicUrlBuilder('http://localhost:8080///');
        $url = $customBuilder->buildGuideUrl('es', 'test-code');
        $this->assertSame('http://localhost:8080/guide/es.html?code=test-code', $url);
    }
}
