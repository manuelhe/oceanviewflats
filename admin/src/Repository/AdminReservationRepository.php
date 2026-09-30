<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Repository;

use OceanViewFlats\Domain\Reservation\PdoReservationRepository;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use OceanViewFlats\Domain\Reservation\Search\PdoReservationSearchAdapter;
use OceanViewFlats\Domain\Reservation\Search\ReservationSearchCriteria;
use OceanViewFlats\Domain\Reservation\Search\ReservationSearchInterface;
use PDO;

/**
 * Backward-compatible adapter delegating administrative reservation queries,
 * search, and persistence to domain-level adapters.
 *
 * @deprecated Use \OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface and \OceanViewFlats\Domain\Reservation\Search\ReservationSearchInterface instead.
 */
final class AdminReservationRepository
{
    private readonly ReservationSearchInterface $search;
    private readonly ReservationRepositoryInterface $repository;

    public function __construct(
        private readonly PDO $pdo,
        ?ReservationSearchInterface $search = null,
        ?ReservationRepositoryInterface $repository = null
    ) {
        $this->search = $search ?? new PdoReservationSearchAdapter($this->pdo);
        $this->repository = $repository ?? new PdoReservationRepository($this->pdo);
    }

    /**
     * Searches and paginates reservations based on multi-field filters.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function searchReservations(array $filters = []): array
    {
        if (!isset($filters['limit']) && !isset($filters['per_page']) && !isset($filters['perPage'])) {
            $filters['limit'] = 25;
        }
        $criteria = ReservationSearchCriteria::fromArray($filters);
        return $this->search->search($criteria)->toArray();
    }

    /**
     * Retrieves a reservation by UID along with its associated audit trail and refunds.
     *
     * @return array{
     *     reservation: array<string, mixed>,
     *     audit_logs: list<array<string, mixed>>,
     *     refunds: list<array<string, mixed>>
     * }|null
     */
    public function findReservationWithAuditTrail(string $uid): ?array
    {
        $dossier = $this->search->findWithAuditTrail($uid);
        return $dossier?->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRefundsByReservationUid(string $uid): array
    {
        return $this->search->findRefunds($uid);
    }

    /**
     * Retrieves reservation record for update with row locking where supported.
     *
     * @return array<string, mixed>|null
     */
    public function findReservationForUpdate(string $uid): ?array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = ($driver === 'mysql') ? ' FOR UPDATE' : '';

        $stmt = $this->pdo->prepare("SELECT * FROM reservations WHERE reservation_uid = :uid{$lockClause} LIMIT 1");
        $stmt->execute(['uid' => $uid]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Retrieves guest registry record for a given reservation UID.
     *
     * @return array<string, mixed>|null
     */
    public function findGuestRegistryByReservationUid(string $uid): ?array
    {
        return $this->search->findGuestRegistry($uid);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findReservationByUid(string $uid): ?array
    {
        $reservation = $this->repository->findByUid($uid);
        return $reservation?->toArray();
    }

    public function updateRegistryCompleted(string $uid, ?string $doorCode = null): bool
    {
        return $this->repository->markRegistryCompleted($uid, new \DateTimeImmutable(), $doorCode) !== null;
    }

    public function updateDoorCode(string $uid, string $doorCode): bool
    {
        return $this->repository->updateDoorCode($uid, $doorCode) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createManualReservation(array $data): string
    {
        $reservation = Reservation::fromArray($data);
        $saved = $this->repository->save($reservation);
        return $saved->reservationUid;
    }

    /**
     * Updates reservation status to 'cancelled', records refund in reservation_refunds if amount > 0,
     * and updates refunded_amount and payment_status atomically.
     */
    public function cancelReservationWithRefund(
        string $uid,
        string $reason,
        string $refundType,
        float $refundAmount,
        ?string $mpRefundId,
        ?string $mpPaymentId,
        ?int $adminUserId,
        string $source = 'admin_pms'
    ): bool {
        $reservation = $this->repository->findByUid($uid);
        if ($reservation === null) {
            return false;
        }

        $totalPrice = $reservation->totalPrice;
        $currentRefunded = $reservation->refundedAmount;
        $refundAmount = max(0.0, round($refundAmount, 2));
        $newRefundedAmount = round($currentRefunded + $refundAmount, 2);

        $newPaymentStatus = $reservation->paymentStatus ?? 'pending_payment';
        if ($newRefundedAmount >= $totalPrice && $totalPrice > 0) {
            $newPaymentStatus = 'refunded';
        } elseif ($newRefundedAmount > 0) {
            $newPaymentStatus = 'partially_refunded';
        }

        // 1. Insert refund record if amount > 0
        if ($refundAmount > 0) {
            $this->search->recordRefund([
                'reservation_uid' => $uid,
                'mercadopago_refund_id' => $mpRefundId,
                'mercadopago_payment_id' => $mpPaymentId !== null && $mpPaymentId !== '' ? $mpPaymentId : 'offline',
                'amount' => $refundAmount,
                'status' => 'approved',
                'reason' => $reason,
                'source' => $source,
                'admin_user_id' => $adminUserId,
            ]);
        }

        // 2. Append cancel note to existing notes
        $cancelNote = '[Cancelled ' . date('Y-m-d H:i') . '] ' . $reason;
        if ($refundAmount > 0) {
            $cancelNote .= ' (Refund: COP ' . number_format($refundAmount, 2) . ', type: ' . $refundType . ')';
        } else {
            $cancelNote .= ' (Policy retention: No refund)';
        }
        $existingNotes = $reservation->notes !== null ? trim($reservation->notes) : '';
        $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote;

        $cancelled = $reservation->withRefund(
            additionalRefundAmount: $refundAmount,
            notes: $updatedNotes,
            status: ReservationStatus::CANCELLED,
            paymentStatus: $newPaymentStatus
        );

        $this->repository->save($cancelled);
        return true;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
}
