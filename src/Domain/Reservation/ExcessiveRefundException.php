<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DomainException;

/**
 * Exception thrown when a requested refund amount exceeds the reservation's refundable balance.
 */
final class ExcessiveRefundException extends DomainException
{
    public function __construct(
        public readonly string $reservationUid,
        public readonly float $requestedAmount,
        public readonly float $refundableBalance,
        string $message = ''
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf(
                'Requested refund of COP %.2f exceeds remaining refundable balance of COP %.2f for reservation %s',
                $requestedAmount,
                $refundableBalance,
                $reservationUid
            );
        parent::__construct($msg);
    }

    public static function forAmount(string $reservationUid, float $requestedAmount, float $refundableBalance): self
    {
        return new self($reservationUid, $requestedAmount, $refundableBalance);
    }

    public function getErrorCode(): string
    {
        return 'EXCESSIVE_REFUND';
    }

    public function getReservationUid(): string
    {
        return $this->reservationUid;
    }

    public function getRequestedAmount(): float
    {
        return $this->requestedAmount;
    }

    public function getRemainingBalance(): float
    {
        return $this->refundableBalance;
    }
}
