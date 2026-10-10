<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation\InMemory;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\InMemoryReservationRepository;
use OceanViewFlats\Domain\Reservation\Port\ReservationPersistencePort;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationConflictException;

use OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface;

/**
 * In-memory test adapter for ReservationPersistencePort with rollback simulation.
 */
class InMemoryReservationPersistenceAdapter implements ReservationPersistencePort
{
    /**
     * @var array<string, Reservation>
     */
    private array $reservations = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $refunds = [];

    private ?ReservationRepositoryInterface $repository;

    /**
     * @param list<Reservation> $initialReservations
     */
    public function __construct(
        array $initialReservations = [],
        ?ReservationRepositoryInterface $repository = null
    ) {
        $this->repository = $repository;
        foreach ($initialReservations as $reservation) {
            $this->reservations[$reservation->reservationUid] = $reservation;
            $this->repository?->save($reservation);
        }
        if ($this->repository instanceof InMemoryReservationRepository) {
            foreach ($this->repository->all() as $res) {
                $this->reservations[$res->reservationUid] = $res;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getReservation(string $reservationUid): ?Reservation
    {
        if (isset($this->reservations[$reservationUid])) {
            return $this->reservations[$reservationUid];
        }

        if ($this->repository !== null) {
            $reservation = $this->repository->findByUid($reservationUid);
            if ($reservation !== null) {
                $this->reservations[$reservationUid] = $reservation;
                return $reservation;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function save(Reservation $reservation): Reservation
    {
        $this->reservations[$reservation->reservationUid] = $reservation;
        $this->repository?->save($reservation);
        return $reservation;
    }

    /**
     * @inheritDoc
     */
    public function holdAtomic(
        Reservation $reservation,
        ?DateTimeImmutable $now = null
    ): Reservation {
        foreach ($this->reservations as $existing) {
            if ($existing->reservationUid === $reservation->reservationUid) {
                continue;
            }

            if ($existing->propertyId !== $reservation->propertyId) {
                continue;
            }

            if ($existing->overlaps($reservation->checkIn, $reservation->checkOut) && $existing->isHolding($now)) {
                throw ReservationConflictException::forDates(
                    $reservation->propertyId,
                    $reservation->checkIn,
                    $reservation->checkOut,
                    sprintf('Dates conflict with active reservation %s', $existing->reservationUid)
                );
            }
        }

        $this->reservations[$reservation->reservationUid] = $reservation;
        $this->repository?->save($reservation);
        return $reservation;
    }

    /**
     * @inheritDoc
     */
    public function recordRefund(array $data): void
    {
        $this->refunds[] = $data;
        $this->repository?->recordRefund($data);
    }

    /**
     * @inheritDoc
     */
    public function executeInTransaction(callable $operation): mixed
    {
        $snapshotReservations = $this->reservations;
        $snapshotRefunds = $this->refunds;

        try {
            return $operation();
        } catch (\Throwable $e) {
            $this->reservations = $snapshotReservations;
            $this->refunds = $snapshotRefunds;
            throw $e;
        }
    }

    /**
     * @return array<string, Reservation>
     */
    public function all(): array
    {
        return $this->reservations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRefunds(): array
    {
        return $this->refunds;
    }

    public function clear(): void
    {
        $this->reservations = [];
        $this->refunds = [];
    }
}
