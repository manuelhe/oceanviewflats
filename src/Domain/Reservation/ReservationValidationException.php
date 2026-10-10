<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use InvalidArgumentException;

final class ReservationValidationException extends InvalidArgumentException
{
    /**
     * @var string[]
     */
    private array $violations;
    private string $errorCode;
    private ?int $expected;
    private ?int $actual;

    /**
     * @param string[] $violations
     */
    public function __construct(
        string $message,
        array $violations = [],
        string $errorCode = 'VALIDATION_ERROR',
        ?int $expected = null,
        ?int $actual = null
    ) {
        parent::__construct($message);
        $this->violations = $violations;
        $this->errorCode = $errorCode;
        $this->expected = $expected;
        $this->actual = $actual;
    }

    /**
     * @param string[] $violations
     */
    public static function withViolations(array $violations, string $errorCode = 'VALIDATION_ERROR'): self
    {
        $message = sprintf('Reservation validation failed: %s', implode('; ', $violations));
        return new self($message, $violations, $errorCode);
    }

    public static function minimumStayViolated(int $minimumNights, int $requestedNights): self
    {
        return new self(
            sprintf('Seasonal minimum stay not met: required %d nights, requested %d nights', $minimumNights, $requestedNights),
            [sprintf('Minimum stay of %d nights required (requested %d)', $minimumNights, $requestedNights)],
            'MINIMUM_STAY_VIOLATED',
            $minimumNights,
            $requestedNights
        );
    }

    public static function invalidDates(string $reason): self
    {
        return new self(sprintf('Invalid reservation dates: %s', $reason), [$reason], 'INVALID_DATES');
    }

    /**
     * @return string[]
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getExpected(): ?int
    {
        return $this->expected;
    }

    public function getActual(): ?int
    {
        return $this->actual;
    }
}
