<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use OceanViewFlats\Domain\Quote\Quote;

/**
 * Result DTO returned upon confirming or recording a reservation.
 * Encapsulates statutory credential protection indicators per ADR 0001 and ADR 0009.
 */
final class ReservationResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?Quote $quote = null,
        public readonly bool $wasChannelBlockAbsorbed = false,
        public readonly ?string $absorbedChannelBlockUid = null,
        public readonly bool $accessPinAllocated = true,
        public readonly bool $accessPinReleasedToGuest = false,
        public readonly ?string $doorCode = null
    ) {
    }
}
