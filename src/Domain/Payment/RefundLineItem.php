<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use JsonSerializable;

/**
 * Immutable typed value object representing an itemized refund on a payment.
 */
final class RefundLineItem implements JsonSerializable
{
    public function __construct(
        public readonly string $refundId,
        public readonly string $paymentId,
        public readonly float $amount,
        public readonly string $status,
        public readonly string $createdAt
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $refundId = '';
        if (isset($data['id'])) {
            $refundId = (string) $data['id'];
        } elseif (isset($data['refund_id'])) {
            $refundId = (string) $data['refund_id'];
        }

        $paymentId = isset($data['payment_id']) ? (string) $data['payment_id'] : '';
        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        $status = isset($data['status']) ? (string) $data['status'] : 'approved';
        
        $createdAt = date('c');
        if (isset($data['date_created']) && is_string($data['date_created'])) {
            $createdAt = $data['date_created'];
        } elseif (isset($data['created_at']) && is_string($data['created_at'])) {
            $createdAt = $data['created_at'];
        }

        return new self(
            refundId: $refundId,
            paymentId: $paymentId,
            amount: $amount,
            status: $status,
            createdAt: $createdAt
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'refund_id' => $this->refundId,
            'payment_id' => $this->paymentId,
            'amount' => $this->amount,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
