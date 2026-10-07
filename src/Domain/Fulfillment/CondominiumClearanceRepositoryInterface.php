<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

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
}
