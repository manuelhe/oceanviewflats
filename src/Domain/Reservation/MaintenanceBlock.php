<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Authoritative administrative maintenance hold on a Property's calendar per ADR 0006.
 * Maintenance blocks are persisted in the database and projected onto outbound feeds.
 */
final class MaintenanceBlock
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $startDate, // YYYY-MM-DD
        public readonly string $endDate,   // YYYY-MM-DD
        public readonly string $reason,
        public readonly ?int $id = null,
        public readonly ?int $createdBy = null,
        public readonly ?DateTimeImmutable $createdAt = null
    ) {
        if ($this->propertyId === '') {
            throw new InvalidArgumentException('Property ID cannot be empty');
        }
        if ($this->startDate >= $this->endDate) {
            throw new InvalidArgumentException(
                sprintf('End date (%s) must be after start date (%s)', $this->endDate, $this->startDate)
            );
        }
        if (trim($this->reason) === '') {
            throw new InvalidArgumentException('Reason cannot be empty');
        }
    }

    /**
     * Determines whether the given stay date range overlaps with this maintenance block.
     * Uses half-open night intervals [startDate, endDate) consistent with short-term rental standards.
     * Check-in on checkout date does not constitute an overlap.
     */
    public function overlaps(string $checkIn, string $checkOut): bool
    {
        return ($this->startDate < $checkOut) && ($this->endDate > $checkIn);
    }

    /**
     * Returns an array of ISO 8601 date strings for each night occupied by this block.
     *
     * @return list<string>
     */
    public function nights(): array
    {
        $start = new DateTimeImmutable($this->startDate);
        $end = new DateTimeImmutable($this->endDate);
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);

        $nights = [];
        foreach ($period as $date) {
            $nights[] = $date->format('Y-m-d');
        }

        return $nights;
    }

    /**
     * Determines whether this maintenance block is in the past (already concluded).
     */
    public function isConcluded(?DateTimeImmutable $now = null): bool
    {
        $currentDate = ($now ?? new DateTimeImmutable('today'))->format('Y-m-d');
        return $this->endDate <= $currentDate;
    }
}
