<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Result DTO returned upon successfully cancelling a reservation.
 */
final class CancellationResult
{
    public function __construct(
        public readonly Reservation $reservation,
        public readonly ?RefundReceipt $refundReceipt = null,
        public readonly float $refundAmountCop = 0.0,
        public readonly float $policyRetentionCop = 0.0,
        public readonly bool $emailSent = false,
        public readonly ?int $auditLogId = null
    ) {
    }
}
