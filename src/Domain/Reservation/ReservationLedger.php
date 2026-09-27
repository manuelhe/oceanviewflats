<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Deep Reservation Ledger service.
 * Consolidates availability checking across ephemeral Channel Blocks (ADR 0002)
 * and direct database reservations, enforcing dynamic hold windows (ADR 0003).
 */
final class ReservationLedger implements ReservationLedgerInterface
{
    public function __construct(
        private readonly ReservationRepositoryInterface $repository,
        private readonly ChannelBlockSourceInterface $channelBlockSource,
        private readonly int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        private readonly int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ) {}

    public static function createDefault(PDO $pdo, ?string $cacheDir = null): self
    {
        return new self(
            repository: new PdoReservationRepository($pdo),
            channelBlockSource: $cacheDir !== null
                ? new FileCacheChannelBlockSource($cacheDir)
                : FileCacheChannelBlockSource::createDefault()
        );
    }

    public function isAvailable(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        ?DateTimeImmutable $now = null
    ): bool {
        return count($this->getConflictReasons($propertyId, $checkIn, $checkOut, $now)) === 0;
    }

    public function getConflictReasons(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        ?DateTimeImmutable $now = null
    ): array {
        if ($checkIn >= $checkOut) {
            return [sprintf('Check-out date (%s) must be after check-in date (%s)', $checkOut, $checkIn)];
        }

        $reasons = [];

        // 1. Evaluate ephemeral in-memory Channel Blocks (ADR 0002)
        $channelBlocks = $this->channelBlockSource->getBlocks($propertyId);
        foreach ($channelBlocks as $block) {
            if ($block->overlaps($checkIn, $checkOut)) {
                $reasons[] = sprintf(
                    'Dates overlap external %s channel block (%s to %s)',
                    $block->source,
                    $block->startDate,
                    $block->endDate
                );
            }
        }

        // 2. Evaluate active direct reservations with dynamic hold windows (ADR 0003)
        $conflictingReservations = $this->repository->findOverlappingActive(
            propertyId: $propertyId,
            checkIn: $checkIn,
            checkOut: $checkOut,
            now: $now,
            standardHoldMinutes: $this->standardHoldMinutes,
            voucherHoldHours: $this->voucherHoldHours
        );

        foreach ($conflictingReservations as $res) {
            $statusLabel = $res->status->value;
            if ($res->status->isPending()) {
                $statusLabel .= $res->isVoucherHold() ? ' (efecty 72h voucher hold)' : ' (30m standard hold)';
            }
            $reasons[] = sprintf(
                'Dates overlap active direct reservation %s (%s to %s, status: %s)',
                $res->reservationUid,
                $res->checkIn,
                $res->checkOut,
                $statusLabel
            );
        }

        return $reasons;
    }

    public function getBlockedNights(
        string $propertyId,
        ?DateTimeImmutable $now = null
    ): array {
        // Ephemeral channel nights
        $channelNights = $this->channelBlockSource->getBlockedNights($propertyId);

        // Active direct reservation nights
        $activeReservations = $this->repository->findActiveByProperty(
            propertyId: $propertyId,
            now: $now,
            standardHoldMinutes: $this->standardHoldMinutes,
            voucherHoldHours: $this->voucherHoldHours
        );

        $reservationNights = [];
        foreach ($activeReservations as $res) {
            foreach ($res->nights() as $night) {
                $reservationNights[] = $night;
            }
        }

        $allBlocked = array_unique(array_merge($channelNights, $reservationNights));
        sort($allBlocked);

        return array_values($allBlocked);
    }

    public function hold(
        Reservation $reservation,
        ?DateTimeImmutable $now = null
    ): Reservation {
        $conflicts = $this->getConflictReasons(
            propertyId: $reservation->propertyId,
            checkIn: $reservation->checkIn,
            checkOut: $reservation->checkOut,
            now: $now
        );

        if (!empty($conflicts)) {
            throw ReservationConflictException::forDates(
                propertyId: $reservation->propertyId,
                checkIn: $reservation->checkIn,
                checkOut: $reservation->checkOut,
                conflictReason: implode('; ', $conflicts)
            );
        }

        // Ephemeral channel blocks were checked in memory, but NEVER written to the database (ADR 0002)
        return $this->repository->save($reservation);
    }

    public function confirm(
        string $reservationUid,
        string $paymentId,
        ?string $paymentStatus = null,
        ?string $paymentDetail = null
    ): Reservation {
        $updated = $this->repository->updateStatus(
            reservationUid: $reservationUid,
            status: ReservationStatus::CONFIRMED,
            paymentId: $paymentId,
            paymentStatus: $paymentStatus,
            paymentDetail: $paymentDetail
        );

        if ($updated === null) {
            throw new InvalidArgumentException(sprintf('Reservation not found with UID: %s', $reservationUid));
        }

        return $updated;
    }

    public function cancel(
        string $reservationUid,
        string $reason = ''
    ): Reservation {
        $updated = $this->repository->updateStatus(
            reservationUid: $reservationUid,
            status: ReservationStatus::CANCELLED,
            paymentDetail: $reason !== '' ? sprintf('Cancelled: %s', $reason) : null
        );

        if ($updated === null) {
            throw new InvalidArgumentException(sprintf('Reservation not found with UID: %s', $reservationUid));
        }

        return $updated;
    }

    public function getReservation(string $reservationUid): ?Reservation
    {
        return $this->repository->findByUid($reservationUid);
    }
}
