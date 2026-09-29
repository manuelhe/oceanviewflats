<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Administrative repository for querying, creating, and removing maintenance blocks
 * in calendar_blocks with author joins and date overlap detection per ADR 0006.
 */
final class AdminCalendarBlockRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Retrieves maintenance blocks, filtered by property ('all', '1606', '1707') and status ('upcoming', 'past', 'all').
     *
     * @return list<array<string, mixed>>
     */
    public function getBlocks(
        string $propertyId = 'all',
        string $filter = 'upcoming',
        ?DateTimeImmutable $now = null
    ): array {
        $today = ($now ?? new DateTimeImmutable('today'))->format('Y-m-d');

        $sql = '
            SELECT cb.*, u.name AS created_by_name
            FROM calendar_blocks cb
            LEFT JOIN admin_users u ON cb.created_by = u.id
            WHERE 1=1
        ';
        $params = [];

        if ($propertyId !== 'all') {
            $sql .= ' AND cb.property_id = :property_id';
            $params['property_id'] = $propertyId;
        }

        if ($filter === 'upcoming') {
            $sql .= ' AND cb.end_date >= :today ORDER BY cb.start_date ASC';
            $params['today'] = $today;
        } elseif ($filter === 'past') {
            $sql .= ' AND cb.end_date < :today ORDER BY cb.start_date DESC';
            $params['today'] = $today;
        } else {
            $sql .= ' ORDER BY cb.start_date DESC';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Finds a single maintenance block by ID with author details.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT cb.*, u.name AS created_by_name
            FROM calendar_blocks cb
            LEFT JOIN admin_users u ON cb.created_by = u.id
            WHERE cb.id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Checks if a proposed maintenance block interval collides with an existing block in calendar_blocks.
     */
    public function hasOverlap(
        string $propertyId,
        string $startDate,
        string $endDate,
        ?int $excludeId = null
    ): bool {
        $sql = '
            SELECT COUNT(*) 
            FROM calendar_blocks 
            WHERE property_id = :property_id 
              AND start_date < :end_date 
              AND end_date > :start_date
        ';
        $params = [
            'property_id' => $propertyId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * Creates a new maintenance block record.
     */
    public function createBlock(
        string $propertyId,
        string $startDate,
        string $endDate,
        string $reason,
        ?int $createdBy = null
    ): int {
        if (!in_array($propertyId, ['1606', '1707'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid property ID: %s', $propertyId));
        }
        if ($startDate >= $endDate) {
            throw new InvalidArgumentException(
                sprintf('End date (%s) must be after start date (%s)', $endDate, $startDate)
            );
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason cannot be empty');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO calendar_blocks (property_id, start_date, end_date, reason, created_by, created_at, updated_at)
            VALUES (:property_id, :start_date, :end_date, :reason, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'property_id' => $propertyId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'reason' => trim($reason),
            'created_by' => $createdBy,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Deletes a maintenance block by ID.
     */
    public function deleteBlock(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_blocks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
