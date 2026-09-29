<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Database-backed rate source that retrieves seasonal rate tiers from MySQL
 * with optional fallback to CsvRateSource and CSV seeding utilities.
 */
final class PdoRateSource implements RateSourceInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?RateSourceInterface $fallbackSource = null
    ) {
    }

    /**
     * @return array<int, RateTier>
     */
    public function getTiersForProperty(string $propertyId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT property_id, start_date, end_date, price_per_night, min_stay 
            FROM property_rates 
            WHERE property_id = :property_id 
            ORDER BY start_date ASC
        ');
        $stmt->execute(['property_id' => $propertyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            if ($this->fallbackSource !== null) {
                return $this->fallbackSource->getTiersForProperty($propertyId);
            }
            return [];
        }

        $tiers = [];
        foreach ($rows as $row) {
            $tiers[] = new RateTier(
                propertyId: (string) $row['property_id'],
                startDate: (string) $row['start_date'],
                endDate: (string) $row['end_date'],
                nightlyRateCop: (float) $row['price_per_night'],
                minimumStay: (int) $row['min_stay']
            );
        }

        return $tiers;
    }

    /**
     * Checks if a proposed seasonal tier date range overlaps with any existing tier for the property.
     */
    public function hasOverlap(
        string $propertyId,
        string $startDate,
        string $endDate,
        ?int $excludeId = null
    ): bool {
        if ($startDate >= $endDate) {
            throw new InvalidArgumentException(
                sprintf('End date (%s) must be after start date (%s).', $endDate, $startDate)
            );
        }

        $sql = '
            SELECT COUNT(*) 
            FROM property_rates 
            WHERE property_id = :property_id 
              AND start_date <= :end_date 
              AND end_date >= :start_date
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
     * Seeds property_rates from a CSV file if the table has no entries for the property.
     *
     * @return int Number of seeded rows
     */
    public function seedFromCsv(string $csvFilePath, ?int $createdBy = null): int
    {
        if (!file_exists($csvFilePath)) {
            throw new RuntimeException("Prices CSV file not found at: {$csvFilePath}");
        }

        $file = fopen($csvFilePath, 'r');
        if ($file === false) {
            throw new RuntimeException("Unable to open prices CSV file at: {$csvFilePath}");
        }

        // Skip header
        fgetcsv($file);

        $insertedCount = 0;
        $insertStmt = $this->pdo->prepare('
            INSERT INTO property_rates (
                property_id, start_date, end_date, season_name, price_per_night, min_stay, created_by
            ) VALUES (
                :property_id, :start_date, :end_date, :season_name, :price_per_night, :min_stay, :created_by
            )
        ');

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) >= 5) {
                $propId = trim($row[0]);
                $startDate = trim($row[1]);
                $endDate = trim($row[2]);
                $rateCop = (float) $row[3];
                $minStay = (int) $row[4];

                // Only seed if not already present/overlapping
                if (!$this->hasOverlap($propId, $startDate, $endDate)) {
                    $insertStmt->execute([
                        'property_id' => $propId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'season_name' => 'Imported Standard Rate',
                        'price_per_night' => $rateCop,
                        'min_stay' => $minStay,
                        'created_by' => $createdBy,
                    ]);
                    $insertedCount++;
                }
            }
        }

        fclose($file);

        return $insertedCount;
    }
}
