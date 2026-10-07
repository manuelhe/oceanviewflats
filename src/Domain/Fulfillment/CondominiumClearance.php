<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use InvalidArgumentException;

/**
 * Immutable entity encapsulating condominium administration clearance state.
 *
 * Tracks outbound synchronization with the external condominium reception portal
 * (Huésped Manager) under ADR 0008.
 */
final class CondominiumClearance
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
    ];

    /**
     * @param ?array<string, mixed> $requestPayload
     */
    public function __construct(
        public readonly string $reservationUid,
        public readonly string $propertyId,
        public readonly string $status = self::STATUS_PENDING,
        public readonly ?string $clearanceNumber = null,
        public readonly ?string $errorMessage = null,
        public readonly ?array $requestPayload = null,
        public readonly int $attempts = 1,
        public readonly ?string $lastAttemptAt = null,
        public readonly ?string $syncedAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?int $id = null,
    ) {
        $trimmedUid = trim($this->reservationUid);
        if ($trimmedUid === '') {
            throw new InvalidArgumentException('Reservation UID cannot be empty.');
        }

        $trimmedProp = trim($this->propertyId);
        if ($trimmedProp === '') {
            throw new InvalidArgumentException('Property ID cannot be empty.');
        }

        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid clearance status: %s.', $this->status));
        }

        if ($this->attempts < 1) {
            throw new InvalidArgumentException('Clearance attempts must be at least 1.');
        }
    }

    /**
     * Factory method creating a pending clearance record for a reservation.
     *
     * @param ?array<string, mixed> $requestPayload
     */
    public static function createPending(
        string $reservationUid,
        string $propertyId,
        ?array $requestPayload = null,
        ?string $lastAttemptAt = null
    ): self {
        $now = date('Y-m-d H:i:s');
        return new self(
            reservationUid: trim($reservationUid),
            propertyId: trim($propertyId),
            status: self::STATUS_PENDING,
            clearanceNumber: null,
            errorMessage: null,
            requestPayload: $requestPayload,
            attempts: 1,
            lastAttemptAt: $lastAttemptAt ?? $now,
            syncedAt: null,
            createdAt: $now,
            updatedAt: $now
        );
    }

    /**
     * Returns a new instance marked as successfully synchronized.
     */
    public function markSynced(string $clearanceNumber, ?string $syncedAt = null): self
    {
        $trimmedClearance = trim($clearanceNumber);
        if ($trimmedClearance === '') {
            throw new InvalidArgumentException('Clearance number cannot be empty when marking synced.');
        }

        $now = date('Y-m-d H:i:s');

        return new self(
            reservationUid: $this->reservationUid,
            propertyId: $this->propertyId,
            status: self::STATUS_SYNCED,
            clearanceNumber: $trimmedClearance,
            errorMessage: null,
            requestPayload: $this->requestPayload,
            attempts: $this->attempts,
            lastAttemptAt: $this->lastAttemptAt ?? $now,
            syncedAt: $syncedAt ?? $now,
            createdAt: $this->createdAt,
            updatedAt: $now,
            id: $this->id
        );
    }

    /**
     * Returns a new instance marked as failed with diagnostic error message.
     */
    public function markFailed(string $errorMessage, ?string $failedAt = null): self
    {
        $trimmedError = trim($errorMessage);
        $now = $failedAt ?? date('Y-m-d H:i:s');

        return new self(
            reservationUid: $this->reservationUid,
            propertyId: $this->propertyId,
            status: self::STATUS_FAILED,
            clearanceNumber: $this->clearanceNumber,
            errorMessage: $trimmedError !== '' ? $trimmedError : 'Unknown error during condominium clearance sync',
            requestPayload: $this->requestPayload,
            attempts: $this->attempts,
            lastAttemptAt: $now,
            syncedAt: $this->syncedAt,
            createdAt: $this->createdAt,
            updatedAt: $now,
            id: $this->id
        );
    }

    /**
     * Returns a new instance with updated request payload without incrementing attempts.
     *
     * @param ?array<string, mixed> $payload
     */
    public function withPayload(?array $payload): self
    {
        return new self(
            reservationUid: $this->reservationUid,
            propertyId: $this->propertyId,
            status: $this->status,
            clearanceNumber: $this->clearanceNumber,
            errorMessage: $this->errorMessage,
            requestPayload: $payload ?? $this->requestPayload,
            attempts: $this->attempts,
            lastAttemptAt: $this->lastAttemptAt,
            syncedAt: $this->syncedAt,
            createdAt: $this->createdAt,
            updatedAt: date('Y-m-d H:i:s'),
            id: $this->id
        );
    }

    /**
     * Returns a new instance incrementing the attempt count and updating timestamp.
     *
     * @param ?array<string, mixed> $newPayload
     */
    public function recordAttempt(?array $newPayload = null): self
    {
        $now = date('Y-m-d H:i:s');

        return new self(
            reservationUid: $this->reservationUid,
            propertyId: $this->propertyId,
            status: $this->status,
            clearanceNumber: $this->clearanceNumber,
            errorMessage: $this->errorMessage,
            requestPayload: $newPayload ?? $this->requestPayload,
            attempts: $this->attempts + 1,
            lastAttemptAt: $now,
            syncedAt: $this->syncedAt,
            createdAt: $this->createdAt,
            updatedAt: $now,
            id: $this->id
        );
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_uid' => $this->reservationUid,
            'property_id' => $this->propertyId,
            'status' => $this->status,
            'clearance_number' => $this->clearanceNumber,
            'error_message' => $this->errorMessage,
            'request_payload' => $this->requestPayload,
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->lastAttemptAt,
            'synced_at' => $this->syncedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $payload = $data['request_payload'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        return new self(
            reservationUid: (string) ($data['reservation_uid'] ?? ''),
            propertyId: (string) ($data['property_id'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_PENDING),
            clearanceNumber: isset($data['clearance_number']) ? (string) $data['clearance_number'] : null,
            errorMessage: isset($data['error_message']) ? (string) $data['error_message'] : null,
            requestPayload: is_array($payload) ? $payload : null,
            attempts: max(1, (int) ($data['attempts'] ?? 1)),
            lastAttemptAt: isset($data['last_attempt_at']) ? (string) $data['last_attempt_at'] : null,
            syncedAt: isset($data['synced_at']) ? (string) $data['synced_at'] : null,
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            id: isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : null,
        );
    }
}
