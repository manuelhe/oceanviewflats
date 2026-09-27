<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Direct Reservation domain entity representing a guest stay contract.
 * Enforces dynamic hold windows based on payment settlement type per ADR 0003.
 */
final class Reservation
{
    public const DEFAULT_STANDARD_HOLD_MINUTES = 30;
    public const DEFAULT_VOUCHER_HOLD_HOURS = 72;

    public function __construct(
        public readonly string $reservationUid,
        public readonly string $propertyId,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly string $guestPhone,
        public readonly string $checkIn,  // YYYY-MM-DD
        public readonly string $checkOut, // YYYY-MM-DD
        public readonly float $totalPrice,
        public readonly ReservationStatus $status = ReservationStatus::PENDING_PAYMENT,
        public readonly ?string $paymentMethodId = null,
        public readonly ?int $id = null,
        public readonly ?string $mercadopagoPreferenceId = null,
        public readonly ?string $mercadopagoPaymentId = null,
        public readonly ?string $paymentStatus = null,
        public readonly ?string $paymentDetail = null,
        public readonly string $lang = 'en',
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null
    ) {
        if ($this->reservationUid === '') {
            throw new InvalidArgumentException('Reservation UID cannot be empty');
        }
        if ($this->propertyId === '') {
            throw new InvalidArgumentException('Property ID cannot be empty');
        }
        if ($this->checkIn >= $this->checkOut) {
            throw new InvalidArgumentException(
                sprintf('Check-out date (%s) must be after check-in date (%s)', $this->checkOut, $this->checkIn)
            );
        }
    }

    /**
     * Determines whether this reservation is actively holding the dates on the calendar.
     *
     * - Confirmed reservations hold dates indefinitely.
     * - Cancelled reservations never hold dates.
     * - Pending reservations hold dates according to their payment settlement rail:
     *     - Efecty Cash Vouchers: 72 hours (ADR 0003)
     *     - Cards / PSE / Instant: 30 minutes (CONTEXT.md)
     */
    public function isHolding(
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = self::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = self::DEFAULT_VOUCHER_HOLD_HOURS
    ): bool {
        if ($this->status->isCancelled()) {
            return false;
        }

        if ($this->status->isConfirmed()) {
            return true;
        }

        // Must be PENDING_PAYMENT
        $currentTime = $now ?? new DateTimeImmutable();
        $createdTime = $this->createdAt ?? $currentTime;

        if ($this->isVoucherHold()) {
            $expirySeconds = $voucherHoldHours * 3600;
        } else {
            $expirySeconds = $standardHoldMinutes * 60;
        }

        $ageSeconds = $currentTime->getTimestamp() - $createdTime->getTimestamp();
        return $ageSeconds < $expirySeconds;
    }

    /**
     * Identifies if this reservation qualifies for the extended offline cash voucher window (ADR 0003).
     */
    public function isVoucherHold(): bool
    {
        return $this->paymentMethodId !== null && strtolower($this->paymentMethodId) === 'efecty';
    }

    /**
     * Evaluates if the stay dates overlap with a given check-in / check-out interval.
     */
    public function overlaps(string $checkIn, string $checkOut): bool
    {
        return ($this->checkIn < $checkOut) && ($this->checkOut > $checkIn);
    }

    /**
     * Returns an array of ISO 8601 date strings for each night occupied by this reservation.
     *
     * @return list<string>
     */
    public function nights(): array
    {
        $start = new DateTimeImmutable($this->checkIn);
        $end = new DateTimeImmutable($this->checkOut);
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $nights = [];
        foreach ($period as $date) {
            $nights[] = $date->format('Y-m-d');
        }

        return $nights;
    }

    /**
     * Creates an updated clone with a new status and optional payment information.
     */
    public function withStatus(
        ReservationStatus $status,
        ?string $paymentId = null,
        ?string $paymentStatus = null,
        ?string $paymentDetail = null,
        ?DateTimeImmutable $updatedAt = null
    ): self {
        return new self(
            reservationUid: $this->reservationUid,
            propertyId: $this->propertyId,
            guestName: $this->guestName,
            guestEmail: $this->guestEmail,
            guestPhone: $this->guestPhone,
            checkIn: $this->checkIn,
            checkOut: $this->checkOut,
            totalPrice: $this->totalPrice,
            status: $status,
            paymentMethodId: $this->paymentMethodId,
            id: $this->id,
            mercadopagoPreferenceId: $this->mercadopagoPreferenceId,
            mercadopagoPaymentId: $paymentId ?? $this->mercadopagoPaymentId,
            paymentStatus: $paymentStatus ?? $this->paymentStatus,
            paymentDetail: $paymentDetail ?? $this->paymentDetail,
            lang: $this->lang,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? new DateTimeImmutable()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_uid' => $this->reservationUid,
            'property_id' => $this->propertyId,
            'guest_name' => $this->guestName,
            'guest_email' => $this->guestEmail,
            'guest_phone' => $this->guestPhone,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'total_price' => $this->totalPrice,
            'status' => $this->status->value,
            'payment_method_id' => $this->paymentMethodId,
            'mercadopago_preference_id' => $this->mercadopagoPreferenceId,
            'mercadopago_payment_id' => $this->mercadopagoPaymentId,
            'payment_status' => $this->paymentStatus,
            'payment_detail' => $this->paymentDetail,
            'lang' => $this->lang,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
