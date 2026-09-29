<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use JsonSerializable;

/**
 * Immutable typed value object representing the result of a payment creation/charge attempt.
 */
final class PaymentGatewayResult implements JsonSerializable
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $paymentId,
        public readonly string $status,
        public readonly ?string $statusDetail,
        public readonly ?string $paymentMethodId,
        public readonly float $transactionAmount,
        public readonly ?string $externalResourceUrl = null,
        public readonly ?string $barcode = null,
        public readonly ?string $verificationCode = null,
        public readonly array $rawResponse = [],
        public readonly ?string $errorMessage = null
    ) {
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'in_process'], true);
    }

    public function isRejected(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled'], true) || (!$this->success && !$this->isPending());
    }

    /**
     * Factory to build result from Mercado Pago response array.
     *
     * @param array<string, mixed> $data
     * @param PaymentIntent|null $intent
     * @return self
     */
    public static function fromArray(array $data, ?PaymentIntent $intent = null): self
    {
        $status = (string) ($data['status'] ?? 'unknown');
        $statusDetail = isset($data['status_detail']) ? (string) $data['status_detail'] : null;

        $paymentId = null;
        if (isset($data['id'])) {
            $paymentId = (string) $data['id'];
        } elseif (isset($data['payment_id'])) {
            $paymentId = (string) $data['payment_id'];
        }

        $paymentMethodId = isset($data['payment_method_id'])
            ? (string) $data['payment_method_id']
            : $intent?->paymentMethodId;

        $transactionAmount = isset($data['transaction_amount'])
            ? (float) $data['transaction_amount']
            : ($intent !== null ? $intent->transactionAmount : 0.0);

        /** @var array<string, mixed> $details */
        $details = isset($data['transaction_details']) && is_array($data['transaction_details'])
            ? $data['transaction_details']
            : [];

        $externalResourceUrl = null;
        if (isset($details['external_resource_url']) && is_string($details['external_resource_url'])) {
            $externalResourceUrl = $details['external_resource_url'];
        } elseif (isset($data['external_resource_url']) && is_string($data['external_resource_url'])) {
            $externalResourceUrl = $data['external_resource_url'];
        }

        $barcode = null;
        if (isset($details['barcode']['content']) && is_string($details['barcode']['content'])) {
            $barcode = $details['barcode']['content'];
        } elseif (isset($data['barcode']['content']) && is_string($data['barcode']['content'])) {
            $barcode = $data['barcode']['content'];
        } elseif (isset($data['barcode']) && is_string($data['barcode'])) {
            $barcode = $data['barcode'];
        }

        $verificationCode = null;
        if (isset($details['verification_code']) && (is_string($details['verification_code']) || is_numeric($details['verification_code']))) {
            $verificationCode = (string) $details['verification_code'];
        } elseif (isset($data['verification_code']) && (is_string($data['verification_code']) || is_numeric($data['verification_code']))) {
            $verificationCode = (string) $data['verification_code'];
        }

        $success = in_array($status, ['approved', 'in_process', 'pending'], true);

        $errorMessage = null;
        if (!$success) {
            if (isset($data['message']) && is_string($data['message'])) {
                $errorMessage = $data['message'];
            } elseif (isset($data['cause'][0]['description']) && is_string($data['cause'][0]['description'])) {
                $errorMessage = $data['cause'][0]['description'];
            } elseif ($statusDetail !== null && $statusDetail !== '') {
                $errorMessage = $statusDetail;
            } else {
                $errorMessage = 'Payment was declined or rejected';
            }
        }

        return new self(
            success: $success,
            paymentId: $paymentId,
            status: $status,
            statusDetail: $statusDetail,
            paymentMethodId: $paymentMethodId,
            transactionAmount: $transactionAmount,
            externalResourceUrl: $externalResourceUrl,
            barcode: $barcode,
            verificationCode: $verificationCode,
            rawResponse: $data,
            errorMessage: $errorMessage
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'success' => $this->success,
            'payment_id' => $this->paymentId,
            'status' => $this->status,
            'status_detail' => $this->statusDetail,
            'payment_method_id' => $this->paymentMethodId,
            'transaction_amount' => $this->transactionAmount,
            'external_resource_url' => $this->externalResourceUrl,
            'barcode' => $this->barcode,
            'verification_code' => $this->verificationCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
