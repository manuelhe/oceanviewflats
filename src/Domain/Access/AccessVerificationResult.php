<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Access;

use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Value object representing the outcome of an access verification request.
 * Enforces that access credentials are only packaged when verified is true.
 */
final class AccessVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $reason = null,
        public readonly ?Reservation $reservation = null,
        public readonly ?AccessCredentials $credentials = null,
        public readonly ?string $registryUrl = null
    ) {}

    public static function verified(
        Reservation $reservation,
        AccessCredentials $credentials,
        string $message = 'Access credentials verified.'
    ): self {
        return new self(
            verified: true,
            status: 'verified',
            message: $message,
            reason: null,
            reservation: $reservation,
            credentials: $credentials,
            registryUrl: null
        );
    }

    public static function registryRequired(
        Reservation $reservation,
        string $registryUrl,
        string $message = 'Guest registry must be submitted before access credentials are released.'
    ): self {
        return new self(
            verified: false,
            status: 'registry_required',
            message: $message,
            reason: 'registry_required',
            reservation: $reservation,
            credentials: null,
            registryUrl: $registryUrl
        );
    }

    public static function notFound(string $message = 'Reservation not found or invalid access token.'): self
    {
        return new self(
            verified: false,
            status: 'not_found',
            message: $message,
            reason: 'not_found',
            reservation: null,
            credentials: null,
            registryUrl: null
        );
    }

    public static function unauthorized(string $message = 'Reservation is not confirmed or has been cancelled.'): self
    {
        return new self(
            verified: false,
            status: 'unauthorized',
            message: $message,
            reason: 'unauthorized',
            reservation: null,
            credentials: null,
            registryUrl: null
        );
    }

    /**
     * Converts to an associative array for API responses.
     * Note: Access credentials are NEVER included if verified is false.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'success' => $this->status === 'verified' || $this->status === 'registry_required',
            'verified' => $this->verified,
            'status' => $this->status,
            'message' => $this->message,
        ];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        if ($this->registryUrl !== null) {
            $payload['registry_url'] = $this->registryUrl;
        }

        if ($this->reservation !== null) {
            $payload['reservation'] = [
                'property_id' => $this->reservation->propertyId,
                'check_in' => $this->reservation->checkIn,
                'check_out' => $this->reservation->checkOut,
                'guest_name' => $this->reservation->guestName,
                'status' => $this->reservation->status->value,
                'registry_completed' => $this->reservation->registryCompleted,
            ];
        }

        if ($this->verified && $this->credentials !== null) {
            $payload['credentials'] = $this->credentials->toArray();
        }

        return $payload;
    }
}
