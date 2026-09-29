<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use RuntimeException;
use Throwable;

/**
 * Domain exception thrown when an interaction with the payment gateway fails.
 */
final class PaymentGatewayException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        public readonly int $httpCode = 500,
        public readonly ?string $errorType = null,
        public readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpCode, $previous);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
