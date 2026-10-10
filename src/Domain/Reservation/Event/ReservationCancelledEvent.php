<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Event;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\ActorContext;
use OceanViewFlats\Domain\Reservation\CancellationResult;
use OceanViewFlats\Domain\Reservation\Reservation;

final class ReservationCancelledEvent
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ActorContext $actorContext,
        public readonly CancellationResult $cancellationResult,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable()
    ) {
    }
}
