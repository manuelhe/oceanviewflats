<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation\InMemory;

use OceanViewFlats\Domain\Reservation\GatewayRefundException;
use OceanViewFlats\Domain\Reservation\Port\PaymentRefundPort;
use OceanViewFlats\Domain\Reservation\RefundReceipt;

/**
 * In-memory test adapter for PaymentRefundPort with failure simulation.
 */
class InMemoryPaymentRefundAdapter implements PaymentRefundPort
{
    private bool $shouldFail = false;
    private string $failureReason = 'Simulated gateway refund failure';

    /**
     * @var array<string, RefundReceipt>
     */
    private array $issuedRefunds = [];

    /**
     * @var list<array{paymentId: string, amountCop: float, idempotencyKey: string}>
     */
    private array $refundCalls = [];

    public function simulateFailure(string $reason = 'Simulated gateway refund failure'): self
    {
        $this->shouldFail = true;
        $this->failureReason = $reason;
        return $this;
    }

    public function simulateSuccess(): self
    {
        $this->shouldFail = false;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function issueRefund(string $paymentId, float $amountCop, string $idempotencyKey): RefundReceipt
    {
        $this->refundCalls[] = [
            'paymentId' => $paymentId,
            'amountCop' => $amountCop,
            'idempotencyKey' => $idempotencyKey,
        ];

        if ($this->shouldFail) {
            throw GatewayRefundException::forPayment($paymentId, $amountCop, $this->failureReason);
        }

        $receipt = new RefundReceipt(
            refundId: 'ref_sim_' . bin2hex(random_bytes(4)),
            paymentId: $paymentId,
            amountCop: $amountCop,
            status: 'approved',
            idempotencyKey: $idempotencyKey,
            rawResponse: [
                'id' => 'ref_sim_' . bin2hex(random_bytes(4)),
                'payment_id' => $paymentId,
                'amount' => $amountCop,
                'status' => 'approved',
            ]
        );

        $this->issuedRefunds[$idempotencyKey] = $receipt;
        return $receipt;
    }

    /**
     * @return array<string, RefundReceipt>
     */
    public function getIssuedRefunds(): array
    {
        return $this->issuedRefunds;
    }

    /**
     * @return list<array{paymentId: string, amountCop: float, idempotencyKey: string}>
     */
    public function getRefundCalls(): array
    {
        return $this->refundCalls;
    }

    public function reset(): void
    {
        $this->shouldFail = false;
        $this->failureReason = 'Simulated gateway refund failure';
        $this->issuedRefunds = [];
        $this->refundCalls = [];
    }
}
