<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

/**
 * Interface for retrieving seasonal rate tiers for properties.
 */
interface RateSourceInterface
{
    /**
     * @return array<int, RateTier>
     */
    public function getTiersForProperty(string $propertyId): array;
}
