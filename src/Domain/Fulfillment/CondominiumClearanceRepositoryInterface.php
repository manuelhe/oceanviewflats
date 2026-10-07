<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use DateTimeInterface;
use OceanViewFlats\Domain\Reservation\Dashboard\OperationalAlert;

/**
 * Repository interface for managing persistent Condominium Clearance entities.
 */
interface CondominiumClearanceRepositoryInterface
{
    /**
     * Persists a clearance record (inserts or updates on reservation_uid conflict).
     */
    public function save(CondominiumClearance $clearance): void;

    /**
     * Finds clearance record by unique reservation UID.
     */
    public function findByReservationUid(string $reservationUid): ?CondominiumClearance;

    /**
     * Finds recent failed clearances for operator alerting and retry processing.
     *
     * @return list<CondominiumClearance>
     */
    public function findFailedClearances(int $limit = 50): array;

    /**
     * Finds active failed clearance alerts for the dashboard operational hub.
     *
     * @param string $propertyId 'all' or property code ('1707' or '1606')
     * @param string|DateTimeInterface|null $now Reference date/time (defaults to current date)
     * @return list<OperationalAlert>
     */
    public function getFailedClearanceAlerts(string $propertyId = 'all', string|DateTimeInterface|null $now = null): array;
}
