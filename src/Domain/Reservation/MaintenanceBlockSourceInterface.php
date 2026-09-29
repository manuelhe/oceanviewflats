<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Port for retrieving authoritative administrative maintenance calendar blocks per ADR 0006.
 */
interface MaintenanceBlockSourceInterface
{
    /**
     * Retrieves all maintenance blocks for a property.
     *
     * @return list<MaintenanceBlock>
     */
    public function getBlocks(string $propertyId): array;

    /**
     * Retrieves all blocked night dates (YYYY-MM-DD) caused by maintenance blocks for a property.
     *
     * @return list<string>
     */
    public function getBlockedNights(string $propertyId): array;
}
