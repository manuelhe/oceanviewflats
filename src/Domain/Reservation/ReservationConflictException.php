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
        public readonly string $conflictReasons = ''
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forDates(string $propertyId, string $checkIn, string $checkOut, string $conflictReason): self
    {
        return new self(
            sprintf(
                'Calendar conflict for property %s from %s to %s: %s',
                $propertyId,
                $checkIn,
                $checkOut,
                $conflictReason
            ),
            0,
            null,
            $conflictReason
        );
    }
}
