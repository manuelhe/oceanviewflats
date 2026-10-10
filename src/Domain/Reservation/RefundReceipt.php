<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Value object representing a confirmed refund receipt from an external payment gateway.
 */
final class RefundReceipt
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public readonly string $refundId,
        public readonly string $paymentId,
        public readonly float $amountCop,
        public readonly string $status = 'approved',
        public readonly string $idempotencyKey = '',
        public readonly array $rawResponse = []
    ) {
    }
}
