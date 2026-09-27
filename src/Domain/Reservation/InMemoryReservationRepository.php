<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;

/**
 * Fast, deterministic in-memory repository for unit and integration testing.
 */
final class InMemoryReservationRepository implements ReservationRepositoryInterface
{
    /** @var array<string, Reservation> */
    private array $records = [];

    private int $nextId = 1;

    public function save(Reservation $reservation): Reservation
    {
        $id = $reservation->id ?? $this->nextId++;
        $createdAt = $reservation->createdAt ?? new DateTimeImmutable();

        $saved = new Reservation(
            reservationUid: $reservation->reservationUid,
            propertyId: $reservation->propertyId,
            guestName: $reservation->guestName,
            guestEmail: $reservation->guestEmail,
            guestPhone: $reservation->guestPhone,
            checkIn: $reservation->checkIn,
            checkOut: $reservation->checkOut,
            totalPrice: $reservation->totalPrice,
            status: $reservation->status,
            paymentMethodId: $reservation->paymentMethodId,
            id: $id,
            mercadopagoPreferenceId: $reservation->mercadopagoPreferenceId,
            mercadopagoPaymentId: $reservation->mercadopagoPaymentId,
            paymentStatus: $reservation->paymentStatus,
            paymentDetail: $reservation->paymentDetail,
            lang: $reservation->lang,
            createdAt: $createdAt,
            updatedAt: $reservation->updatedAt
        );

        $this->records[$saved->reservationUid] = $saved;
        return $saved;
    }

    public function findByUid(string $reservationUid): ?Reservation
    {
        return $this->records[$reservationUid] ?? null;
    }

    public function findActiveByProperty(
        string $propertyId,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array {
        $active = [];
        foreach ($this->records as $reservation) {
            if ($reservation->propertyId === $propertyId && $reservation->isHolding($now, $standardHoldMinutes, $voucherHoldHours)) {
                $active[] = $reservation;
            }
        }
        return $active;
    }

    public function findOverlappingActive(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array {
        $overlapping = [];
        $active = $this->findActiveByProperty($propertyId, $now, $standardHoldMinutes, $voucherHoldHours);

        foreach ($active as $reservation) {
            if ($reservation->overlaps($checkIn, $checkOut)) {
                $overlapping[] = $reservation;
            }
        }

        return $overlapping;
    }

    public function updateStatus(
        string $reservationUid,
        ReservationStatus $status,
        ?string $paymentId = null,
        ?string $paymentStatus = null,
        ?string $paymentDetail = null
    ): ?Reservation {
        $existing = $this->findByUid($reservationUid);
        if ($existing === null) {
            return null;
        }

        $updated = $existing->withStatus(
            status: $status,
            paymentId: $paymentId,
            paymentStatus: $paymentStatus,
            paymentDetail: $paymentDetail,
            updatedAt: new DateTimeImmutable()
        );

        $this->records[$reservationUid] = $updated;
        return $updated;
    }

    public function holdAtomic(
        Reservation $reservation,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): Reservation {
        $overlapping = $this->findOverlappingActive(
            propertyId: $reservation->propertyId,
            checkIn: $reservation->checkIn,
            checkOut: $reservation->checkOut,
            now: $now,
            standardHoldMinutes: $standardHoldMinutes,
            voucherHoldHours: $voucherHoldHours
        );

        if (!empty($overlapping)) {
            $conflict = $overlapping[0];
            throw ReservationConflictException::forDates(
                $reservation->propertyId,
                $reservation->checkIn,
                $reservation->checkOut,
                sprintf('Dates overlap active direct reservation %s', $conflict->reservationUid)
            );
        }

        return $this->save($reservation);
    }

    /**
     * Helper for test state inspection.
     *
     * @return array<string, Reservation>
     */
    public function all(): array
    {
        return $this->records;
    }
}
