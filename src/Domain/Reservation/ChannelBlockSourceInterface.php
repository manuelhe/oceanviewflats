<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Port for retrieving ephemeral external OTA calendar blocks per ADR 0002.
 */
interface ChannelBlockSourceInterface
{
    /**
     * Retrieves all active ephemeral channel blocks for a property.
     *
     * @return list<ChannelBlock>
     */
    public function getBlocks(string $propertyId): array;

    /**
     * Retrieves all blocked night dates (YYYY-MM-DD) for a property.
     *
     * @return list<string>
     */
    public function getBlockedNights(string $propertyId): array;
}
