<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use RuntimeException;
use Throwable;

final class GatewayRefundException extends RuntimeException
{
    private string $paymentId;
    private float $amountCop;

    public function __construct(
        string $message,
        string $paymentId = '',
        float $amountCop = 0.0,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->paymentId = $paymentId;
        $this->amountCop = $amountCop;
    }

    public static function forPayment(string $paymentId, float $amountCop, string $reason, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Gateway refund failed for payment %s (amount %s COP): %s', $paymentId, number_format($amountCop, 2, '.', ''), $reason),
            $paymentId,
            $amountCop,
            0,
            $previous
        );
    }

    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    public function getAmountCop(): float
    {
        return $this->amountCop;
    }
}
