<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use JsonSerializable;

/**
 * Immutable typed value object representing an intent to process a payment.
 */
final class PaymentIntent implements JsonSerializable
{
    public function __construct(
        public readonly string $reservationUid,
        public readonly float $transactionAmount,
        public readonly string $paymentMethodId,
        public readonly string $payerEmail,
        public readonly ?string $token = null,
        public readonly int $installments = 1,
        public readonly ?string $issuerId = null,
        public readonly ?string $identificationType = null,
        public readonly ?string $identificationNumber = null,
        public readonly ?string $financialInstitution = null,
        public readonly string $description = '',
        public readonly ?string $notificationUrl = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $payerEntityType = null
    ) {
    }

    /**
     * Serializes intent into a Mercado Pago /v1/payments compatible array payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'transaction_amount' => round($this->transactionAmount, 2),
            'description' => $this->description,
            'payment_method_id' => $this->paymentMethodId,
            'external_reference' => $this->reservationUid,
            'payer' => [
                'email' => $this->payerEmail,
            ],
        ];

        if ($this->token !== null && trim($this->token) !== '') {
            $payload['token'] = trim($this->token);
            $payload['installments'] = max(1, $this->installments);
        } elseif ($this->installments > 1) {
            $payload['installments'] = $this->installments;
        }

        if ($this->issuerId !== null && trim($this->issuerId) !== '') {
            $payload['issuer_id'] = trim($this->issuerId);
        }

        if (
            $this->identificationType !== null && trim($this->identificationType) !== '' &&
            $this->identificationNumber !== null && trim($this->identificationNumber) !== ''
        ) {
            $payload['payer']['identification'] = [
                'type' => trim($this->identificationType),
                'number' => trim($this->identificationNumber),
            ];
        }

        if ($this->payerEntityType !== null && trim($this->payerEntityType) !== '') {
            $payload['payer']['entity_type'] = trim($this->payerEntityType);
        }

        if ($this->financialInstitution !== null && trim($this->financialInstitution) !== '') {
            $payload['transaction_details'] = [
                'financial_institution' => trim($this->financialInstitution),
            ];
        }

        if ($this->ipAddress !== null && trim($this->ipAddress) !== '') {
            $payload['additional_info'] = [
                'ip_address' => trim($this->ipAddress),
            ];
        }

        if ($this->notificationUrl !== null && trim($this->notificationUrl) !== '') {
            $payload['notification_url'] = trim($this->notificationUrl);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
