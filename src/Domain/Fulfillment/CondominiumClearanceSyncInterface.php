<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationNotFoundException;

/**
 * Interface for condominium clearance outbound synchronization adapters.
 */
interface CondominiumClearanceSyncInterface
{
    /**
     * Deep synchronization entry point loading reservation and guest registry from persistence.
     *
     * @throws ReservationNotFoundException If reservation does not exist
     * @throws GuestRegistryRequiredException If guest registry has not been submitted
     */
    public function syncForReservation(string $reservationUid, ?AdminContext $admin = null): CondominiumClearance;

    /**
     * Deep synchronization entry point utilizing in-memory reservation and guest registry submission models.
     */
    public function syncSubmission(Reservation $reservation, GuestRegistrySubmission $submission, ?AdminContext $admin = null): CondominiumClearance;

    /**
     * Synchronizes guest and reservation details with the condominium administration portal.
     *
     * @param string $reservationUid Unique reservation identifier
     * @param string $propertyId Property code ('1707' or '1606')
     * @param string $checkIn Check-in date in YYYY-MM-DD format
     * @param string $checkOut Check-out date in YYYY-MM-DD format
     * @param array<int, OccupantDetails|array<string, mixed>> $guests List of occupants (primary guest + companions)
     * @param ?string $carPlates Optional vehicle license plate
     * @param ?string $notes Optional administrative observation / notes
     * @return CondominiumClearance Persisted clearance state
     */
    public function sync(
        string $reservationUid,
        string $propertyId,
        string $checkIn,
        string $checkOut,
        array $guests,
        ?string $carPlates = null,
        ?string $notes = null
    ): CondominiumClearance;
}

