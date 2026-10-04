<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

final class OperationalAlert
{
    /**
     * @param array<string, mixed> $actionPayload
     */
    public function __construct(
        public readonly string $id,
        public readonly AlertType $type,
        public readonly AlertSeverity $severity,
        public readonly string $propertyId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $dueDate, // YYYY-MM-DD
        public readonly ?string $reservationUid = null,
        public readonly ?string $guestName = null,
        public readonly ?string $channelBlockUid = null,
        public readonly ?string $source = null,
        public readonly array $actionPayload = []
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'property_id' => $this->propertyId,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->dueDate,
            'reservation_uid' => $this->reservationUid,
            'guest_name' => $this->guestName,
            'channel_block_uid' => $this->channelBlockUid,
            'source' => $this->source,
            'action_payload' => $this->actionPayload,
        ];
    }
}
