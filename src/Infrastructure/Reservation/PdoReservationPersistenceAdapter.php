<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation;

use DateTimeImmutable;
use PDO;
use Throwable;
use OceanViewFlats\Domain\Reservation\PdoReservationRepository;
use OceanViewFlats\Domain\Reservation\Port\ReservationPersistencePort;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface;

/**
 * Production PDO persistence adapter for reservation storage and concurrency controls.
 */
final class PdoReservationPersistenceAdapter implements ReservationPersistencePort
{
    private ReservationRepositoryInterface $repository;

    public function __construct(
        private readonly PDO $pdo,
        ?ReservationRepositoryInterface $repository = null
    ) {
        $this->repository = $repository ?? new PdoReservationRepository($this->pdo);
    }

    /**
     * @inheritDoc
     */
    public function getReservation(string $reservationUid): ?Reservation
    {
        return $this->repository->findByUid($reservationUid);
    }

    /**
     * @inheritDoc
     */
    public function save(Reservation $reservation): Reservation
    {
        return $this->repository->save($reservation);
    }

    /**
     * @inheritDoc
     */
    public function holdAtomic(
        Reservation $reservation,
        ?DateTimeImmutable $now = null
    ): Reservation {
        return $this->repository->holdAtomic($reservation, $now);
    }

    /**
     * @inheritDoc
     */
    public function recordRefund(array $data): void
    {
        $this->repository->recordRefund($data);
    }

    /**
     * @inheritDoc
     */
    public function executeInTransaction(callable $operation): mixed
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $operation();
            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if (!$alreadyInTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
