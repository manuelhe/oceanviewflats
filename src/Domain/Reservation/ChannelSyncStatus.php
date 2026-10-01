<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Value object representing the health, synchronization timestamps, and metrics
 * for an inbound Online Travel Agency (Airbnb) calendar feed (ADR 0002).
 */
final class ChannelSyncStatus implements JsonSerializable
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly string $propertyId,
        private readonly string $status,
        private readonly ?string $lastSyncedAt,
        private readonly string $lastAttemptedAt,
        private readonly ?int $httpCode,
        private readonly int $blockedNightsCount,
        private readonly ?string $errorMessage = null,
        private readonly ?string $initiatedBy = null,
        private readonly ?string $feedUrl = null,
    ) {
        if (!in_array($this->status, [self::STATUS_HEALTHY, self::STATUS_DEGRADED, self::STATUS_ERROR], true)) {
            throw new InvalidArgumentException("Invalid channel sync status: {$this->status}");
        }
    }

    /**
     * Reconstruct a ChannelSyncStatus instance from raw array data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $property = (string) ($data['property'] ?? $data['property_id'] ?? '');
        $status = (string) ($data['status'] ?? self::STATUS_ERROR);
        $lastSyncedAt = isset($data['last_synced_at']) && is_string($data['last_synced_at'])
            ? $data['last_synced_at']
            : null;
        $lastAttemptedAt = (string) ($data['last_attempted_at'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM));
        $httpCode = isset($data['http_code']) && is_numeric($data['http_code'])
            ? (int) $data['http_code']
            : null;
        $blockedNightsCount = (int) ($data['blocked_nights_count'] ?? 0);
        $errorMessage = isset($data['error_message']) && is_string($data['error_message'])
            ? $data['error_message']
            : null;
        $initiatedBy = isset($data['initiated_by']) && is_string($data['initiated_by'])
            ? $data['initiated_by']
            : null;
        $feedUrl = isset($data['feed_url']) && is_string($data['feed_url'])
            ? $data['feed_url']
            : null;

        return new self(
            propertyId: $property,
            status: $status,
            lastSyncedAt: $lastSyncedAt,
            lastAttemptedAt: $lastAttemptedAt,
            httpCode: $httpCode,
            blockedNightsCount: $blockedNightsCount,
            errorMessage: $errorMessage,
            initiatedBy: $initiatedBy,
            feedUrl: $feedUrl,
        );
    }

    public function getPropertyId(): string
    {
        return $this->propertyId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLastSyncedAt(): ?string
    {
        return $this->lastSyncedAt;
    }

    public function getLastAttemptedAt(): string
    {
        return $this->lastAttemptedAt;
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function getBlockedNightsCount(): int
    {
        return $this->blockedNightsCount;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getInitiatedBy(): ?string
    {
        return $this->initiatedBy;
    }

    public function getFeedUrl(): ?string
    {
        return $this->feedUrl;
    }

    public function isHealthy(): bool
    {
        return $this->status === self::STATUS_HEALTHY;
    }

    public function isDegraded(): bool
    {
        return $this->status === self::STATUS_DEGRADED;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property' => $this->propertyId,
            'status' => $this->status,
            'last_synced_at' => $this->lastSyncedAt,
            'last_attempted_at' => $this->lastAttemptedAt,
            'http_code' => $this->httpCode,
            'blocked_nights_count' => $this->blockedNightsCount,
            'error_message' => $this->errorMessage,
            'initiated_by' => $this->initiatedBy,
            'feed_url' => $this->feedUrl,
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
