<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

final class OperationsEvent
{
    public function __construct(
        public readonly string $date, // YYYY-MM-DD
        public readonly MovementType $movementType,
        public readonly string $propertyId,
        public readonly string $reservationUid,
        public readonly string $guestName,
        public readonly ?string $guestPhone,
        public readonly string $status, // confirmed, pending_payment
        public readonly bool $registryCompleted,
        public readonly ?string $doorCode,
        public readonly string $source, // web, manual, airbnb
        public readonly ?string $externalConfirmationCode = null,
        public readonly ?string $departingReservationUid = null, // for TURNOVER
        public readonly ?string $departingGuestName = null       // for TURNOVER
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'movement_type' => $this->movementType->value,
            'property_id' => $this->propertyId,
            'reservation_uid' => $this->reservationUid,
            'guest_name' => $this->guestName,
            'guest_phone' => $this->guestPhone,
            'status' => $this->status,
            'registry_completed' => $this->registryCompleted,
            'door_code' => $this->doorCode,
            'source' => $this->source,
            'external_confirmation_code' => $this->externalConfirmationCode,
            'departing_reservation_uid' => $this->departingReservationUid,
            'departing_guest_name' => $this->departingGuestName,
        ];
    }
}
