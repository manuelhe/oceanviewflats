<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Port;

use OceanViewFlats\Domain\Reservation\GatewayRefundException;
use OceanViewFlats\Domain\Reservation\RefundReceipt;

/**
 * Hexagonal port for external payment gateway refund dispatches.
 */
interface PaymentRefundPort
{
    /**
     * Issues a monetary refund through the external payment gateway.
     *
     * @param string $paymentId External payment gateway payment ID.
     * @param float $amountCop Amount to refund in COP.
     * @param string $idempotencyKey Deterministic idempotency key to prevent double refunds.
     * @return RefundReceipt
     * @throws GatewayRefundException On gateway failure, decline, or network timeout.
     */
    public function issueRefund(string $paymentId, float $amountCop, string $idempotencyKey): RefundReceipt;
}
