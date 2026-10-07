<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Access;

use InvalidArgumentException;

/**
 * Value object representing released physical and network access credentials for a property.
 * Strictly gated by Guest Registry completion per ADR 0001.
 */
final class AccessCredentials
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $doorCode,
        public readonly string $wifiSsid,
        public readonly string $wifiPassword,
        public readonly ?string $parkingSpot = null
    ) {
        if ($this->propertyId === '') {
            throw new InvalidArgumentException('Property ID cannot be empty');
        }
        if ($this->doorCode === '') {
            throw new InvalidArgumentException('Door code cannot be empty');
        }
    }

    /**
     * @return array{property_id: string, door_code: string, wifi_ssid: string, wifi_password: string, parking_spot: ?string}
     */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'door_code' => $this->doorCode,
            'wifi_ssid' => $this->wifiSsid,
            'wifi_password' => $this->wifiPassword,
            'parking_spot' => $this->parkingSpot,
        ];
    }
}
