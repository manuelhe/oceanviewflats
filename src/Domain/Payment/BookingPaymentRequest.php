<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use JsonSerializable;

/**
 * Immutable typed Data Transfer Object representing a booking payment checkout request.
 */
final class BookingPaymentRequest implements JsonSerializable
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $checkIn,
        public readonly string $checkOut,
        public readonly string $guestName,
        public readonly string $guestEmail,
        public readonly string $guestPhone,
        public readonly string $paymentMethodId,
        public readonly ?string $token = null,
        public readonly int $installments = 1,
        public readonly ?string $issuerId = null,
        public readonly ?string $identificationType = null,
        public readonly ?string $identificationNumber = null,
        public readonly ?string $financialInstitution = null,
        public readonly string $lang = 'en',
        public readonly ?string $clientIp = null,
        public readonly ?string $idempotencyKey = null
    ) {
    }

    /**
     * Factory to build request from raw associative array.
     *
     * @param array<string, mixed> $data
     * @param string $lang
     * @param string|null $clientIp
     * @return self
     */
    public static function fromArray(array $data, string $lang = 'en', ?string $clientIp = null): self
    {
        $propertyId = (string) ($data['property_id'] ?? '');
        $checkIn = (string) ($data['check_in'] ?? '');
        $checkOut = (string) ($data['check_out'] ?? '');
        $guestName = (string) ($data['guest_name'] ?? '');
        $guestEmail = (string) ($data['guest_email'] ?? ($data['payer']['email'] ?? ''));
        $guestPhone = (string) ($data['guest_phone'] ?? '');
        $paymentMethodId = (string) ($data['payment_method_id'] ?? '');

        $token = isset($data['token']) && is_string($data['token']) && trim($data['token']) !== ''
            ? trim($data['token'])
            : null;

        $installments = isset($data['installments']) ? (int) $data['installments'] : 1;
        if ($installments < 1) {
            $installments = 1;
        }

        $issuerId = isset($data['issuer_id']) && (is_string($data['issuer_id']) || is_numeric($data['issuer_id']))
            ? (string) $data['issuer_id']
            : null;

        $idType = null;
        if (isset($data['payer']['identification']['type']) && is_string($data['payer']['identification']['type'])) {
            $idType = trim($data['payer']['identification']['type']);
        } elseif (isset($data['identification_type']) && is_string($data['identification_type'])) {
            $idType = trim($data['identification_type']);
        }

        $idNumber = null;
        if (isset($data['payer']['identification']['number']) && (is_string($data['payer']['identification']['number']) || is_numeric($data['payer']['identification']['number']))) {
            $idNumber = trim((string) $data['payer']['identification']['number']);
        } elseif (isset($data['identification_number']) && (is_string($data['identification_number']) || is_numeric($data['identification_number']))) {
            $idNumber = trim((string) $data['identification_number']);
        }

        $financialInstitution = null;
        if (isset($data['transaction_details']['financial_institution']) && is_string($data['transaction_details']['financial_institution'])) {
            $financialInstitution = trim($data['transaction_details']['financial_institution']);
        } elseif (isset($data['financial_institution']) && is_string($data['financial_institution'])) {
            $financialInstitution = trim($data['financial_institution']);
        }

        $effectiveLang = isset($data['lang']) && is_string($data['lang']) && trim($data['lang']) !== ''
            ? strtolower(trim($data['lang']))
            : strtolower($lang);

        $effectiveClientIp = isset($data['client_ip']) && is_string($data['client_ip']) && trim($data['client_ip']) !== ''
            ? trim($data['client_ip'])
            : $clientIp;

        $idempotencyKey = null;
        if (isset($data['idempotency_key']) && is_string($data['idempotency_key']) && trim($data['idempotency_key']) !== '') {
            $idempotencyKey = trim($data['idempotency_key']);
        }

        return new self(
            propertyId: $propertyId,
            checkIn: $checkIn,
            checkOut: $checkOut,
            guestName: $guestName,
            guestEmail: $guestEmail,
            guestPhone: $guestPhone,
            paymentMethodId: $paymentMethodId,
            token: $token,
            installments: $installments,
            issuerId: $issuerId,
            identificationType: $idType,
            identificationNumber: $idNumber,
            financialInstitution: $financialInstitution,
            lang: $effectiveLang,
            clientIp: $effectiveClientIp,
            idempotencyKey: $idempotencyKey
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property_id' => $this->propertyId,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'guest_name' => $this->guestName,
            'guest_email' => $this->guestEmail,
            'guest_phone' => $this->guestPhone,
            'payment_method_id' => $this->paymentMethodId,
            'token' => $this->token,
            'installments' => $this->installments,
            'issuer_id' => $this->issuerId,
            'identification_type' => $this->identificationType,
            'identification_number' => $this->identificationNumber,
            'financial_institution' => $this->financialInstitution,
            'lang' => $this->lang,
            'client_ip' => $this->clientIp,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
