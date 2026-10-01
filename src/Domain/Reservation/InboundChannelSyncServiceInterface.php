<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Domain service interface managing inbound synchronization of external OTA (Airbnb) iCalendar feeds.
 * Ephemeral blocks are cached to local JSON files adhering to ADR 0002 without database mutations.
 */
interface InboundChannelSyncServiceInterface
{
    /**
     * Synchronize a specific property calendar feed.
     *
     * @param string $propertyId E.g. '1606' or '1707'
     * @param bool $force When true, bypasses the 60-second cooldown window
     * @param string $initiatedBy Originating actor ('admin', 'public_traffic', 'cron', etc.)
     * @return ChannelSyncResult
     */
    public function sync(string $propertyId, bool $force = false, string $initiatedBy = 'system'): ChannelSyncResult;

    /**
     * Synchronize all configured property calendar feeds.
     *
     * @param bool $force When true, bypasses the 60-second cooldown window
     * @param string $initiatedBy Originating actor ('admin', 'public_traffic', 'cron', etc.)
     * @return array<string|int, ChannelSyncResult> Keyed by property ID
     */
    public function syncAll(bool $force = false, string $initiatedBy = 'system'): array;

    /**
     * Retrieve the recorded sync health status for a specific property.
     *
     * @param string $propertyId
     * @return ChannelSyncStatus|null Null if property has never been synced or tracked
     */
    public function getStatus(string $propertyId): ?ChannelSyncStatus;

    /**
     * Retrieve sync health statuses for all tracked properties.
     *
     * @return array<string|int, ChannelSyncStatus> Keyed by property ID
     */
    public function getAllStatuses(): array;

    /**
     * Retrieve configured property feed URLs.
     *
     * @return array<string|int, string> Keyed by property ID
     */
    public function getFeedUrls(): array;

    /**
     * Parse RFC 5545 iCalendar content into sorted, deduplicated blocked nights (YYYY-MM-DD).
     *
     * @param string $icalContent
     * @return list<string>
     */
    public function parseIcalToBlockedNights(string $icalContent): array;

    /**
     * Check if a property's cache is missing or older than $maxAgeSeconds.
     *
     * @param string $propertyId
     * @param int $maxAgeSeconds Default 900s (15 minutes)
     * @return bool
     */
    public function isCacheStale(string $propertyId, int $maxAgeSeconds = 900): bool;

    /**
     * Check whether a cache file (avail_{property}.json) exists on disk for the given property.
     *
     * @param string $propertyId
     * @return bool
     */
    public function hasCacheFile(string $propertyId): bool;

    /**
     * Read the cached blocked nights from disk if present.
     *
     * @param string $propertyId
     * @return list<string>|null Null if cache file does not exist or is invalid
     */
    public function getCachedNights(string $propertyId): ?array;
}
