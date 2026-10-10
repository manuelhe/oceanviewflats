<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;
use OceanViewFlats\Domain\Quote\Quote;

/**
 * Result DTO returned after successfully placing a direct checkout hold.
 */
final class DirectHoldResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly Quote $quote,
        public readonly DateTimeImmutable $holdExpiresAt
    ) {
    }
}
