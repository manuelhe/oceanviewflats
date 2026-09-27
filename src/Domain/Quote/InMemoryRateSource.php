<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

/**
 * In-memory rate source for fast, isolated unit tests.
 */
final class InMemoryRateSource implements RateSourceInterface
{
    /** @var array<string, array<int, RateTier>> */
    private array $tiers = [];

    /**
     * @param array<int, RateTier> $initialTiers
     */
    public function __construct(array $initialTiers = [])
    {
        foreach ($initialTiers as $tier) {
            $this->addTier($tier);
        }
    }

    public function addTier(RateTier $tier): void
    {
        $this->tiers[$tier->propertyId][] = $tier;
    }

    /**
     * @return array<int, RateTier>
     */
    public function getTiersForProperty(string $propertyId): array
    {
        return $this->tiers[$propertyId] ?? [];
    }
}
