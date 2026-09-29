<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateTimeImmutable;
use PDO;

/**
 * PDO database-backed adapter for administrative maintenance blocks per ADR 0006.
 */
final class PdoMaintenanceBlockSource implements MaintenanceBlockSourceInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<MaintenanceBlock>
     */
    public function getBlocks(string $propertyId): array
    {
        try {
            $stmt = $this->pdo->prepare('
                SELECT id, property_id, start_date, end_date, reason, created_by, created_at
                FROM calendar_blocks
                WHERE property_id = :property_id
                ORDER BY start_date ASC
            ');
            $stmt->execute(['property_id' => $propertyId]);

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist")) {
                return [];
            }
            throw $e;
        }

        $blocks = [];
        foreach ($rows as $row) {
            $createdAt = isset($row['created_at']) && is_string($row['created_at'])
                ? new DateTimeImmutable($row['created_at'])
                : null;

            $blocks[] = new MaintenanceBlock(
                propertyId: (string) $row['property_id'],
                startDate: (string) $row['start_date'],
                endDate: (string) $row['end_date'],
                reason: (string) $row['reason'],
                id: isset($row['id']) ? (int) $row['id'] : null,
                createdBy: isset($row['created_by']) ? (int) $row['created_by'] : null,
                createdAt: $createdAt
            );
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    public function getBlockedNights(string $propertyId): array
    {
        $nights = [];
        foreach ($this->getBlocks($propertyId) as $block) {
            foreach ($block->nights() as $night) {
                $nights[] = $night;
            }
        }

        $unique = array_values(array_unique($nights));
        sort($unique);
        return $unique;
    }

    /**
     * Checks if a proposed stay or block interval collides with an existing maintenance block in the database.
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

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return ((int) $stmt->fetchColumn()) > 0;
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist")) {
                return false;
            }
            throw $e;
        }
    }
}
