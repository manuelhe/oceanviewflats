<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

final class PropertyRateStatus
{
    public function __construct(
        public readonly string $propertyId,
        public readonly float $currentNightlyRate, // Authoritative rate today in COP
        public readonly bool $isSeasonalTierActive,
        public readonly ?string $activeTierName,
        public readonly ?string $activeTierEndDate,
        public readonly ?float $nextTierRate = null,
        public readonly ?string $nextTierName = null,
        public readonly ?string $nextTierStartDate = null
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'current_nightly_rate' => $this->currentNightlyRate,
            'is_seasonal_tier_active' => $this->isSeasonalTierActive,
            'active_tier_name' => $this->activeTierName,
            'active_tier_end_date' => $this->activeTierEndDate,
            'next_tier_rate' => $this->nextTierRate,
            'next_tier_name' => $this->nextTierName,
            'next_tier_start_date' => $this->nextTierStartDate,
        ];
    }
}
