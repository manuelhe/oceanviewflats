<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

interface SpreadsheetSyncInterface
{
    /**
     * Synchronizes a reservation record to Google Sheets.
     *
     * @param Reservation $reservation
     * @param array<string, mixed> $extra Optional payload overrides or metadata
     * @return bool True on successful transmission, false otherwise
     */
    public function sync(Reservation $reservation, array $extra = []): bool;
}
