<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

use InvalidArgumentException;

/**
 * Configuration holder for Property baseline rates, mandatory cleaning fees, and resort fees.
 * Enforces central configuration rules per ADR 0004.
 */
final class PropertyRatesConfig
{
    /**
     * @param array<array-key, array{default_rate: float, cleaning_fee: float, resort_fee: float, default_min_stay: int}> $properties
     */
    public function __construct(
        private readonly array $properties
    ) {
    }

    public static function createDefault(): self
    {
        return new self([
            '1707' => [
                'default_rate' => 450000.0,
                'cleaning_fee' => 100000.0,
                'resort_fee' => 20000.0,
                'default_min_stay' => 2,
            ],
            '1606' => [
                'default_rate' => 350000.0,
                'cleaning_fee' => 80000.0,
                'resort_fee' => 20000.0,
                'default_min_stay' => 2,
            ],
        ]);
    }

    public function isValidProperty(string $propertyId): bool
    {
        return isset($this->properties[$propertyId]);
    }

    public function getDefaultNightlyRate(string $propertyId): float
    {
        $this->assertPropertyExists($propertyId);
        return $this->properties[$propertyId]['default_rate'];
    }

    public function getCleaningFee(string $propertyId): float
    {
        $this->assertPropertyExists($propertyId);
        return $this->properties[$propertyId]['cleaning_fee'];
    }

    public function getResortFee(string $propertyId): float
    {
        $this->assertPropertyExists($propertyId);
        return $this->properties[$propertyId]['resort_fee'];
    }

    public function getDefaultMinimumStay(string $propertyId): int
    {
        $this->assertPropertyExists($propertyId);
        return $this->properties[$propertyId]['default_min_stay'];
    }

    private function assertPropertyExists(string $propertyId): void
    {
        if (!$this->isValidProperty($propertyId)) {
            throw new InvalidArgumentException("Unknown property identifier: '{$propertyId}'");
        }
    }
}
