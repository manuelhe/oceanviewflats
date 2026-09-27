<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

/**
 * Value object representing a seasonal rate tier for a property.
 */
final class RateTier
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly float $nightlyRateCop,
        public readonly int $minimumStay
    ) {
    }

    /**
     * Determines whether a specific date (Y-m-d) falls within this seasonal tier.
     */
    public function coversDate(string $date): bool
    {
        return $date >= $this->startDate && $date <= $this->endDate;
    }
}
