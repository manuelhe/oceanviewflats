<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation\InMemory;

use OceanViewFlats\Domain\Reservation\Event\ReservationCancelledEvent;
use OceanViewFlats\Domain\Reservation\Event\ReservationConfirmedEvent;
use OceanViewFlats\Domain\Reservation\Port\LifecycleEventPublisherPort;

/**
 * In-memory test adapter for LifecycleEventPublisherPort recording published events.
 */
class InMemoryLifecycleEventPublisherAdapter implements LifecycleEventPublisherPort
{
    /**
     * @var list<ReservationConfirmedEvent>
     */
    private array $confirmedEvents = [];

    /**
     * @var list<ReservationCancelledEvent>
     */
    private array $cancelledEvents = [];

    /**
     * @inheritDoc
     */
    public function publishConfirmed(ReservationConfirmedEvent $event): void
    {
        $this->confirmedEvents[] = $event;
    }

    /**
     * @inheritDoc
     */
    public function publishCancelled(ReservationCancelledEvent $event): void
    {
        $this->cancelledEvents[] = $event;
    }

    /**
     * @return list<ReservationConfirmedEvent>
     */
    public function getConfirmedEvents(): array
    {
        return $this->confirmedEvents;
    }

    /**
     * @return list<ReservationCancelledEvent>
     */
    public function getCancelledEvents(): array
    {
        return $this->cancelledEvents;
    }

    public function hasConfirmed(string $reservationUid): bool
    {
        foreach ($this->confirmedEvents as $event) {
            if ($event->reservation->reservationUid === $reservationUid) {
                return true;
            }
        }
        return false;
    }

    public function hasCancelled(string $reservationUid): bool
    {
        foreach ($this->cancelledEvents as $event) {
            if ($event->reservation->reservationUid === $reservationUid) {
                return true;
            }
        }
        return false;
    }

    public function clear(): void
    {
        $this->confirmedEvents = [];
        $this->cancelledEvents = [];
    }
}
