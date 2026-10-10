<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Result DTO representing a dry-run cancellation evaluation.
 */
final class CancellationPreview
{
    public function __construct(
        public readonly string $reservationUid,
        public readonly float $totalPrice,
        public readonly float $alreadyRefundedCop,
        public readonly float $refundableBalanceCop,
        public readonly int $daysUntilCheckIn,
        public readonly float $suggestedPolicyRetentionCop,
        public readonly float $suggestedMaxRefundCop,
        public readonly bool $isOnlinePayment,
        public readonly ?string $mercadopagoPaymentId = null,
        public readonly ReservationStatus $reservationStatus = ReservationStatus::CONFIRMED
    ) {
    }
}
