<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Service;

use RuntimeException;
use Throwable;

final class MercadoPagoRefundException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly ?string $errorCode = null,
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Returns a human-friendly error message suitable for administrative operators.
     */
    public function getUserFriendlyMessage(): string
    {
        if ($this->statusCode === 428 || $this->errorCode === 'insufficient_money_for_refund') {
            return 'Mercado Pago Error: Insufficient merchant balance to issue this refund. Please deposit funds or collect a balance before retrying.';
        }

        if ($this->statusCode === 422 || $this->errorCode === 'refund_period_exceeded') {
            return 'Mercado Pago Error: The refund window for this payment has expired. Coordinate a manual wire transfer instead.';
        }

        if ($this->statusCode === 409 || $this->errorCode === 'active_dispute') {
            return 'Mercado Pago Error: This payment is locked due to an active chargeback or dispute. Resolve the dispute in Mercado Pago first.';
        }

        if ($this->statusCode === 404 || $this->errorCode === 'payment_not_found') {
            return 'Mercado Pago Error: Payment ID was not found on Mercado Pago.';
        }

        if ($this->statusCode === 0 || $this->statusCode === 504) {
            return 'Mercado Pago Network Error: Connection timed out or gateway unreachable. Please retry.';
        }

        return 'Mercado Pago Gateway Error: ' . $this->getMessage();
    }
}
