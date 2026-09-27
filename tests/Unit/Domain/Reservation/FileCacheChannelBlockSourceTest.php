<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use OceanViewFlats\Domain\Reservation\FileCacheChannelBlockSource;
use PHPUnit\Framework\TestCase;

final class FileCacheChannelBlockSourceTest extends TestCase
{
    private string $tempCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCacheDir = sys_get_temp_dir() . '/ovf_test_cache_' . uniqid();
        mkdir($this->tempCacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempCacheDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->tempCacheDir);
        parent::tearDown();
    }

    public function testReturnsEmptyWhenCacheFileDoesNotExist(): void
    {
        $source = new FileCacheChannelBlockSource($this->tempCacheDir);

        $this->assertSame([], $source->getBlockedNights('1606'));
        $this->assertSame([], $source->getBlocks('1606'));
    }

    public function testReadsBlockedNightsFromJsonCacheFile(): void
    {
        $cacheFile = $this->tempCacheDir . '/avail_1606.json';
        file_put_contents($cacheFile, json_encode(['2026-10-01', '2026-10-02', '2026-10-05']));

        $source = new FileCacheChannelBlockSource($this->tempCacheDir);
        $nights = $source->getBlockedNights('1606');

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-05'], $nights);
    }

    public function testGroupsConsecutiveNightsIntoChannelBlocks(): void
    {
        $cacheFile = $this->tempCacheDir . '/avail_1707.json';
        // Nights 10-01 and 10-02 are consecutive -> block from 10-01 to 10-03 (checkout morning)
        // Night 10-05 is standalone -> block from 10-05 to 10-06
        file_put_contents($cacheFile, json_encode(['2026-10-01', '2026-10-02', '2026-10-05']));

        $source = new FileCacheChannelBlockSource($this->tempCacheDir);
        $blocks = $source->getBlocks('1707');

        $this->assertCount(2, $blocks);

        $this->assertSame('1707', $blocks[0]->propertyId);
        $this->assertSame('2026-10-01', $blocks[0]->startDate);
        $this->assertSame('2026-10-03', $blocks[0]->endDate);
        $this->assertSame(['2026-10-01', '2026-10-02'], $blocks[0]->nights());

        $this->assertSame('1707', $blocks[1]->propertyId);
        $this->assertSame('2026-10-05', $blocks[1]->startDate);
        $this->assertSame('2026-10-06', $blocks[1]->endDate);
        $this->assertSame(['2026-10-05'], $blocks[1]->nights());
    }
}
