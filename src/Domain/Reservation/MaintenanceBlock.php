<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use ArrayAccess;
use BadMethodCallException;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Authoritative administrative maintenance hold on a Property's calendar per ADR 0006.
 * Maintenance blocks are persisted in the database and projected onto outbound feeds.
 *
 * Implements ArrayAccess for backwards compatibility with legacy array-based view templates.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class MaintenanceBlock implements ArrayAccess
{
    public readonly ?int $id;
    public readonly string $propertyId;
    public readonly string $startDate; // YYYY-MM-DD
    public readonly string $endDate;   // YYYY-MM-DD
    public readonly string $reason;
    public readonly ?int $createdBy;
    public readonly ?string $createdByName;
    public readonly ?DateTimeImmutable $createdAt;

    public function __construct(
        string $propertyId,
        string $startDate, // YYYY-MM-DD
        string $endDate,   // YYYY-MM-DD
        string $reason,
        ?int $id = null,
        ?int $createdBy = null,
        ?string $createdByName = null,
        DateTimeImmutable|string|null $createdAt = null
    ) {
        if ($propertyId === '') {
            throw new InvalidArgumentException('Property ID cannot be empty');
        }
        if ($startDate >= $endDate) {
            throw new InvalidArgumentException(
                sprintf('End date (%s) must be after start date (%s)', $endDate, $startDate)
            );
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason cannot be empty');
        }

        $this->propertyId = $propertyId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->reason = trim($reason);
        $this->id = $id;
        $this->createdBy = $createdBy;
        $this->createdByName = $createdByName;

        if (is_string($createdAt)) {
            try {
                $this->createdAt = new DateTimeImmutable($createdAt);
            } catch (\Exception) {
                $this->createdAt = null;
            }
        } elseif ($createdAt instanceof DateTimeImmutable) {
            $this->createdAt = $createdAt;
        } else {
            $this->createdAt = null;
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
     * Determines whether a specific date (YYYY-MM-DD) falls within this maintenance block's nights.
     * Uses half-open night intervals [startDate, endDate) consistent with short-term rental standards.
     */
    public function containsDate(string $date): bool
    {
        return $date >= $this->startDate && $date < $this->endDate;
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

    public function getCreatedAtString(): ?string
    {
        return $this->createdAt?->format('Y-m-d H:i:s');
    }

    /**
     * Returns an associative array representation of this maintenance block.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->propertyId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'reason' => $this->reason,
            'created_by' => $this->createdBy,
            'created_by_name' => $this->createdByName,
            'created_at' => $this->getCreatedAtString(),
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, [
            'id',
            'property_id',
            'propertyId',
            'start_date',
            'startDate',
            'end_date',
            'endDate',
            'reason',
            'created_by',
            'createdBy',
            'created_by_name',
            'createdByName',
            'created_at',
            'createdAt',
        ], true) && $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'id' => $this->id,
            'property_id', 'propertyId' => $this->propertyId,
            'start_date', 'startDate' => $this->startDate,
            'end_date', 'endDate' => $this->endDate,
            'reason' => $this->reason,
            'created_by', 'createdBy' => $this->createdBy,
            'created_by_name', 'createdByName' => $this->createdByName,
            'created_at', 'createdAt' => $this->getCreatedAtString(),
            default => null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('MaintenanceBlock entity is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('MaintenanceBlock entity is immutable.');
    }
}
