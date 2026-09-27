<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;

interface ReservationRepositoryInterface
{
    /**
     * Persists a reservation (insert or update).
     */
    public function save(Reservation $reservation): Reservation;

    /**
     * Finds a reservation by its unique business reservation UID (e.g., ovf_...).
     */
    public function findByUid(string $reservationUid): ?Reservation;

    /**
     * Retrieves all actively holding reservations for a property, evaluating dynamic hold expirations.
     *
     * @return list<Reservation>
     */
    public function findActiveByProperty(
        string $propertyId,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array;

    /**
     * Finds any actively holding reservations for a property that overlap with the requested dates.
     *
     * @return list<Reservation>
     */
    public function findOverlappingActive(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array;

    /**
     * Updates the status and payment details of an existing reservation.
     */
    public function updateStatus(
        string $reservationUid,
        ReservationStatus $status,
        ?string $paymentId = null,
        ?string $paymentStatus = null,
        ?string $paymentDetail = null
    ): ?Reservation;

    /**
     * Atomically validates that no active reservation overlaps the requested dates and persists the hold.
     * Prevents race conditions via database-level transaction locks.
     */
    public function holdAtomic(
        Reservation $reservation,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): Reservation;

    /**
     * Marks the guest registry as completed for the specified reservation (ADR 0001).
     */
    public function markRegistryCompleted(
        string $reservationUid,
        ?DateTimeImmutable $completedAt = null
    ): ?Reservation;

    /**
     * Finds a reservation by property and exact stay dates.
     */
    public function findByPropertyAndDates(
        string $propertyId,
        string $checkIn,
        string $checkOut
    ): ?Reservation;
}
