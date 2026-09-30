<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

/**
 * Domain port for seasonal pricing rate tier storage, querying, gap analysis, and CSV seeding.
 */
interface RateRepositoryInterface extends RateSourceInterface
{
    /**
     * Finds a single seasonal rate tier by ID with author details.
     */
    public function findById(int $id): ?RateTier;

    /**
     * Checks if a proposed seasonal tier date range overlaps with any existing tier for the property.
     */
    public function hasOverlap(string $propertyId, string $startDate, string $endDate, ?int $excludeId = null): bool;

    /**
     * Persists a seasonal rate tier (creates if id is null, updates if id is provided).
     */
    public function save(RateTier $tier, ?int $adminUserId = null): RateTier;

    /**
     * Deletes a seasonal rate tier by ID.
     */
    public function delete(int $id): bool;

    /**
     * Retrieves all seasonal rate tiers for a property, optionally filtered by intersecting a given calendar year.
     *
     * @return list<RateTier>
     */
    public function getRatesForProperty(string $propertyId, ?int $year = null): array;

    /**
     * Identifies all calendar gaps within a specified year that have no active seasonal rate tier,
     * falling back to the property's baseline nightly rate.
     *
     * @return list<array{
     *     start_date: string,
     *     end_date: string,
     *     nights: int,
     *     fallback_rate: float
     * }>
     */
    public function detectGaps(string $propertyId, int $year): array;

    /**
     * Seeds property_rates from a CSV file if non-overlapping.
     *
     * @return int Number of seeded rows
     */
    public function seedFromCsv(string $csvFilePath, ?int $createdBy = null): int;
}
