<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use RuntimeException;

final class ReservationConflictException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly string $conflictReasons = '',
        public readonly ?string $propertyId = null,
        public readonly ?string $checkIn = null,
        public readonly ?string $checkOut = null,
        public readonly ?string $conflictDate = null,
        public readonly ?string $source = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forDates(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        string $conflictReason,
        ?string $conflictDate = null,
        ?string $source = null
    ): self {
        if ($conflictDate === null && preg_match('/\((\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})\)/', $conflictReason, $matches)) {
            $conflictDate = $matches[1];
        }
        if ($source === null && preg_match('/external\s+([a-zA-Z0-9_\-]+)\s+channel block/i', $conflictReason, $matches)) {
            $source = strtolower($matches[1]);
        }

        return new self(
            message: sprintf(
                'Calendar conflict for property %s from %s to %s: %s',
                $propertyId,
                $checkIn,
                $checkOut,
                $conflictReason
            ),
            code: 0,
            previous: null,
            conflictReasons: $conflictReason,
            propertyId: $propertyId,
            checkIn: $checkIn,
            checkOut: $checkOut,
            conflictDate: $conflictDate,
            source: $source
        );
    }

    public function isChannelBlock(): bool
    {
        return $this->source !== null
            || stripos($this->conflictReasons, 'channel block') !== false
            || stripos($this->conflictReasons, 'airbnb') !== false;
    }

    public function getConflictDate(): ?string
    {
        return $this->conflictDate ?? $this->checkIn;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }
}
