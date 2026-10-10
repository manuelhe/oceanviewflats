<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Port;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Hexagonal persistence port for reservation storage and concurrency locks.
 */
interface ReservationPersistencePort
{
    /**
     * Retrieves a reservation by unique business reservation UID (e.g. ovf_..., res-man-...).
     */
    public function getReservation(string $reservationUid): ?Reservation;

    /**
     * Persists a reservation entity (insert or update).
     */
    public function save(Reservation $reservation): Reservation;

    /**
     * Atomically validates that no active reservation overlaps the requested dates and persists the hold.
     * Throws ReservationConflictException if a collision occurs.
     */
    public function holdAtomic(
        Reservation $reservation,
        ?DateTimeImmutable $now = null
    ): Reservation;

    /**
     * Records a refund ledger entry associated with a reservation UID.
     *
     * @param array<string, mixed> $data
     */
    public function recordRefund(array $data): void;

    /**
     * Executes the given callable within a database transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function executeInTransaction(callable $operation): mixed;
}
