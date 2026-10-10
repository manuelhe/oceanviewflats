<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DomainException;

/**
 * Exception thrown when attempting an invalid lifecycle transition on a reservation.
 */
final class InvalidReservationStateException extends DomainException
{
    public function __construct(
        public readonly string $reservationUid,
        public readonly string $currentStatus,
        public readonly string $attemptedTransition,
        string $message = ''
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf(
                'Cannot perform transition "%s" on reservation %s with current status "%s"',
                $attemptedTransition,
                $reservationUid,
                $currentStatus
            );
        parent::__construct($msg);
    }

    public static function alreadyCancelled(string $reservationUid, string $attemptedTransition = 'cancel'): self
    {
        return new self(
            reservationUid: $reservationUid,
            currentStatus: 'cancelled',
            attemptedTransition: $attemptedTransition,
            message: sprintf('Reservation %s is already cancelled', $reservationUid)
        );
    }

    public static function alreadyConcluded(string $reservationUid, string $attemptedTransition = 'cancel'): self
    {
        return new self(
            reservationUid: $reservationUid,
            currentStatus: 'concluded',
            attemptedTransition: $attemptedTransition,
            message: sprintf('Reservation %s has already concluded past departure threshold (ADR 0009)', $reservationUid)
        );
    }

    public static function resurrectionRejected(string $reservationUid, string $currentStatus, string $attemptedAction = 'confirm'): self
    {
        return new self(
            reservationUid: $reservationUid,
            currentStatus: $currentStatus,
            attemptedTransition: $attemptedAction,
            message: sprintf(
                'Resurrection defense rejected transition "%s" for terminal reservation %s (status: %s)',
                $attemptedAction,
                $reservationUid,
                $currentStatus
            )
        );
    }

    public function getErrorCode(): string
    {
        return match (true) {
            str_contains($this->getMessage(), 'Resurrection defense') => 'RESURRECTION_REJECTED',
            $this->currentStatus === 'cancelled' => 'ALREADY_CANCELLED',
            $this->currentStatus === 'concluded' => 'ALREADY_CONCLUDED',
            default => 'INVALID_RESERVATION_STATE',
        };
    }

    public function getCurrentState(): string
    {
        return $this->currentStatus;
    }

    public function getAttemptedTransition(): string
    {
        return $this->attemptedTransition;
    }
}
