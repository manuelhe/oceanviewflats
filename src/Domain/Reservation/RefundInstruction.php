<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use InvalidArgumentException;

/**
 * Value object encapsulating refund directives for a reservation cancellation.
 */
final class RefundInstruction
{
    private function __construct(
        public readonly string $type,
        public readonly ?float $amountCop = null
    ) {
    }

    public static function none(): self
    {
        return new self(type: 'none', amountCop: 0.0);
    }

    public static function full(): self
    {
        return new self(type: 'full', amountCop: null);
    }

    public static function partial(float $amountCop): self
    {
        if ($amountCop <= 0) {
            throw new InvalidArgumentException(
                sprintf('Partial refund amount must be greater than zero, got: %.2f', $amountCop)
            );
        }

        return new self(type: 'partial', amountCop: round($amountCop, 2));
    }

    public function isNone(): bool
    {
        return $this->type === 'none';
    }

    public function isFull(): bool
    {
        return $this->type === 'full';
    }

    public function isPartial(): bool
    {
        return $this->type === 'partial';
    }
}
