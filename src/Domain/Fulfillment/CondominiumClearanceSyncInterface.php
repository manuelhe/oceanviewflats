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
}

