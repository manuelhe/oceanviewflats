<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;

/**
 * File cache adapter that ingests ephemeral external OTA blocks (e.g. from cached Airbnb iCal feeds).
 * Satisfies ADR 0002 by reading cached blocks into memory without database writes.
 */
final class FileCacheChannelBlockSource implements ChannelBlockSourceInterface
{
    public function __construct(
        private readonly string $cacheDir
    ) {}

    public static function createDefault(): self
    {
        return new self(dirname(__DIR__, 3) . '/public/cache');
    }

    public function getBlocks(string $propertyId): array
    {
        $blockedNights = $this->getBlockedNights($propertyId);
        if (empty($blockedNights)) {
            return [];
        }

        sort($blockedNights);

        // Group consecutive night dates into ChannelBlock intervals [startDate, endDate)
        $blocks = [];
        $rangeStart = null;
        $prevDate = null;

        foreach ($blockedNights as $night) {
            if ($rangeStart === null) {
                $rangeStart = $night;
                $prevDate = $night;
                continue;
            }

            $expectedNext = (new DateTimeImmutable($prevDate))->modify('+1 day')->format('Y-m-d');
            if ($night === $expectedNext) {
                $prevDate = $night;
            } else {
                $rangeEnd = (new DateTimeImmutable($prevDate))->modify('+1 day')->format('Y-m-d');
                $blocks[] = $this->createChannelBlock($propertyId, $rangeStart, $rangeEnd);
                $rangeStart = $night;
                $prevDate = $night;
            }
        }

        $rangeEnd = (new DateTimeImmutable($prevDate))->modify('+1 day')->format('Y-m-d');
        $blocks[] = $this->createChannelBlock($propertyId, $rangeStart, $rangeEnd);

        return $blocks;
    }

    private function createChannelBlock(string $propertyId, string $startDate, string $endDate): ChannelBlock
    {
        return new ChannelBlock(
            propertyId: $propertyId,
            startDate: $startDate,
            endDate: $endDate,
            source: 'airbnb'
        );
    }

    public function getBlockedNights(string $propertyId): array
    {
        $cacheFile = rtrim($this->cacheDir, '/') . '/avail_' . $propertyId . '.json';
        if (!file_exists($cacheFile)) {
            return [];
        }

        $content = @file_get_contents($cacheFile);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
