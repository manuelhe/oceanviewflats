<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Port;

use OceanViewFlats\Domain\Reservation\Event\ReservationCancelledEvent;
use OceanViewFlats\Domain\Reservation\Event\ReservationConfirmedEvent;

/**
 * Hexagonal port for publishing reservation lifecycle domain events.
 */
interface LifecycleEventPublisherPort
{
    /**
     * Publishes a reservation confirmed domain event.
     */
    public function publishConfirmed(ReservationConfirmedEvent $event): void;

    /**
     * Publishes a reservation cancelled domain event.
     */
    public function publishCancelled(ReservationCancelledEvent $event): void;
}
