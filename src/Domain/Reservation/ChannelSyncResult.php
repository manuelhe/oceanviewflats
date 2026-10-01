<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use JsonSerializable;

/**
 * Value object representing the outcome of a channel synchronization action.
 */
final class ChannelSyncResult implements JsonSerializable
{
    /**
     * @param string $propertyId
     * @param ChannelSyncStatus $status
     * @param bool $wasSkippedDueToCooldown
     * @param list<string> $blockedNights
     * @param string|null $message
     */
    public function __construct(
        private readonly string $propertyId,
        private readonly ChannelSyncStatus $status,
        private readonly bool $wasSkippedDueToCooldown,
        private readonly array $blockedNights,
        private readonly ?string $message = null,
    ) {}

    public function getPropertyId(): string
    {
        return $this->propertyId;
    }

    public function getStatus(): ChannelSyncStatus
    {
        return $this->status;
    }

    public function wasSkippedDueToCooldown(): bool
    {
        return $this->wasSkippedDueToCooldown;
    }

    /**
     * @return list<string>
     */
    public function getBlockedNights(): array
    {
        return $this->blockedNights;
    }

    public function getBlockedNightsCount(): int
    {
        return count($this->blockedNights);
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Indicates whether the sync succeeded or maintained healthy/fresh state.
     */
    public function isSuccess(): bool
    {
        return $this->status->isHealthy() || ($this->wasSkippedDueToCooldown && !$this->status->isError());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'property' => $this->propertyId,
            'was_skipped_due_to_cooldown' => $this->wasSkippedDueToCooldown,
            'status' => $this->status->toArray(),
            'blocked_nights_count' => count($this->blockedNights),
            'message' => $this->message,
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
