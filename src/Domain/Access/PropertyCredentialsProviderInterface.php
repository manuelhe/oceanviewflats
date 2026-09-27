<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Access;

/**
 * Port contract for retrieving authoritative property access credentials.
 */
interface PropertyCredentialsProviderInterface
{
    /**
     * Resolves the access credentials for a given property ID (e.g., '1606', '1707').
     */
    public function getCredentials(string $propertyId): ?AccessCredentials;
}
