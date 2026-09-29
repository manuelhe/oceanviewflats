<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use PDO;

/**
 * Administrative repository for querying, mutating, and analyzing seasonal rate tiers
 * in property_rates with author joins and timeline gap detection.
 */
final class AdminRateRepository
{
    private readonly PropertyRatesConfig $ratesConfig;

    public function __construct(
        private readonly PDO $pdo,
        ?PropertyRatesConfig $ratesConfig = null
    ) {
        $this->ratesConfig = $ratesConfig ?? PropertyRatesConfig::createDefault();
    }

    /**
     * Retrieves all seasonal rate tiers for a property, optionally filtered by intersecting a given calendar year.
     *
     * @return list<array<string, mixed>>
     */
    public function getRatesForProperty(string $propertyId, ?int $year = null): array
    {
        $sql = '
            SELECT pr.*, u.name AS created_by_name
            FROM property_rates pr
            LEFT JOIN admin_users u ON pr.created_by = u.id
            WHERE pr.property_id = :property_id
        ';
        $params = ['property_id' => $propertyId];

        if ($year !== null) {
            $sql .= ' AND pr.start_date <= :year_end AND pr.end_date >= :year_start';
            $params['year_start'] = sprintf('%04d-01-01', $year);
            $params['year_end'] = sprintf('%04d-12-31', $year);
        }

        $sql .= ' ORDER BY pr.start_date ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Finds a single seasonal rate tier by ID with author details.
     *
     * @return array<string, mixed>|null
     */
    public function findRateById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT pr.*, u.name AS created_by_name
            FROM property_rates pr
            LEFT JOIN admin_users u ON pr.created_by = u.id
            WHERE pr.id = :id
        ');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Persists a new seasonal rate tier.
     *
     * @param array{
     *     property_id: string,
     *     start_date: string,
     *     end_date: string,
     *     season_name: string,
     *     price_per_night: float,
     *     min_stay: int,
     *     cleaning_fee?: float,
     *     resort_fee?: float
     * } $data
     */
    public function createRate(array $data, ?int $adminUserId = null): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO property_rates (
                property_id, start_date, end_date, season_name, price_per_night, min_stay, cleaning_fee, resort_fee, created_by
            ) VALUES (
                :property_id, :start_date, :end_date, :season_name, :price_per_night, :min_stay, :cleaning_fee, :resort_fee, :created_by
            )
        ');

        $stmt->execute([
            'property_id' => $data['property_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'season_name' => $data['season_name'],
            'price_per_night' => $data['price_per_night'],
            'min_stay' => $data['min_stay'],
            'cleaning_fee' => $data['cleaning_fee'] ?? 0.0,
            'resort_fee' => $data['resort_fee'] ?? 0.0,
            'created_by' => $adminUserId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Updates an existing seasonal rate tier.
     *
     * @param array{
     *     season_name: string,
     *     start_date: string,
     *     end_date: string,
     *     price_per_night: float,
     *     min_stay: int
     * } $data
     */
    public function updateRate(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE property_rates
            SET season_name = :season_name,
                start_date = :start_date,
                end_date = :end_date,
                price_per_night = :price_per_night,
                min_stay = :min_stay,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');

        return $stmt->execute([
            'id' => $id,
            'season_name' => $data['season_name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'price_per_night' => $data['price_per_night'],
            'min_stay' => $data['min_stay'],
        ]);
    }

    /**
     * Deletes a seasonal rate tier by ID.
     */
    public function deleteRate(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM property_rates WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Identifies all calendar gaps within a specified year that have no active seasonal rate tier,
     * falling back to the property's baseline nightly rate.
     *
     * @return list<array{
     *     start_date: string,
     *     end_date: string,
     *     nights: int,
     *     fallback_rate: float
     * }>
     */
    public function detectGaps(string $propertyId, int $year): array
    {
        $defaultRate = $this->ratesConfig->isValidProperty($propertyId)
            ? $this->ratesConfig->getDefaultNightlyRate($propertyId)
            : 350000.0;

        $yearStartStr = sprintf('%04d-01-01', $year);
        $yearEndStr = sprintf('%04d-12-31', $year);

        $tiers = $this->getRatesForProperty($propertyId, $year);

        $gaps = [];
        $cursorDate = new DateTimeImmutable($yearStartStr);
        $yearEnd = new DateTimeImmutable($yearEndStr);

        foreach ($tiers as $tier) {
            $tierStart = new DateTimeImmutable((string) $tier['start_date']);
            $tierEnd = new DateTimeImmutable((string) $tier['end_date']);

            // Clamp tier start to year boundaries for analysis
            $effectiveStart = $tierStart < $cursorDate ? $cursorDate : $tierStart;

            if ($effectiveStart > $cursorDate) {
                // There is an unpriced gap between cursorDate and day before effectiveStart
                $gapEnd = $effectiveStart->modify('-1 day');
                if ($gapEnd >= $cursorDate) {
                    $nights = (int) $cursorDate->diff($effectiveStart)->days;
                    $gaps[] = [
                        'start_date' => $cursorDate->format('Y-m-d'),
                        'end_date' => $gapEnd->format('Y-m-d'),
                        'nights' => $nights,
                        'fallback_rate' => $defaultRate,
                    ];
                }
            }

            // Move cursor past tier end date (next day)
            $nextDay = $tierEnd->modify('+1 day');
            if ($nextDay > $cursorDate) {
                $cursorDate = $nextDay;
            }

            if ($cursorDate > $yearEnd) {
                break;
            }
        }

        // Check if there is remaining unpriced space until the end of the year
        if ($cursorDate <= $yearEnd) {
            $nights = (int) $cursorDate->diff($yearEnd->modify('+1 day'))->days;
            $gaps[] = [
                'start_date' => $cursorDate->format('Y-m-d'),
                'end_date' => $yearEnd->format('Y-m-d'),
                'nights' => $nights,
                'fallback_rate' => $defaultRate,
            ];
        }

        return $gaps;
    }
}
