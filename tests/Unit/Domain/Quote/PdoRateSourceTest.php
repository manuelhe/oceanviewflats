<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Quote;

use InvalidArgumentException;
use OceanViewFlats\Domain\Quote\InMemoryRateSource;
use OceanViewFlats\Domain\Quote\PdoRateSource;
use OceanViewFlats\Domain\Quote\RateTier;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoRateSourceTest extends TestCase
{
    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('
            CREATE TABLE property_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                season_name TEXT NOT NULL,
                price_per_night REAL NOT NULL,
                min_stay INTEGER NOT NULL DEFAULT 2,
                cleaning_fee REAL NOT NULL DEFAULT 0.0,
                resort_fee REAL NOT NULL DEFAULT 0.0,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        return $pdo;
    }

    public function testGetTiersReturnsDatabaseRowsMappedToRateTiers(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->exec("
            INSERT INTO property_rates (property_id, start_date, end_date, season_name, price_per_night, min_stay)
            VALUES ('1606', '2026-01-01', '2026-06-30', 'Standard Low', 350000, 2),
                   ('1606', '2026-07-01', '2026-08-31', 'Summer High', 450000, 3)
        ");

        $rateSource = new PdoRateSource($pdo);
        $tiers = $rateSource->getTiersForProperty('1606');

        $this->assertCount(2, $tiers);
        $this->assertSame('1606', $tiers[0]->propertyId);
        $this->assertSame('2026-01-01', $tiers[0]->startDate);
        $this->assertSame('2026-06-30', $tiers[0]->endDate);
        $this->assertSame(350000.0, $tiers[0]->nightlyRateCop);
        $this->assertSame(2, $tiers[0]->minimumStay);

        $this->assertSame('2026-07-01', $tiers[1]->startDate);
        $this->assertSame(450000.0, $tiers[1]->nightlyRateCop);
        $this->assertSame(3, $tiers[1]->minimumStay);
    }

    public function testFallsBackToAlternativeSourceWhenDatabaseIsEmpty(): void
    {
        $pdo = $this->createSqlitePdo();
        $fallback = new InMemoryRateSource([
            new RateTier('1707', '2026-01-01', '2026-12-31', 450000.0, 2),
        ]);

        $rateSource = new PdoRateSource($pdo, fallbackSource: $fallback);
        $tiers = $rateSource->getTiersForProperty('1707');

        $this->assertCount(1, $tiers);
        $this->assertSame(450000.0, $tiers[0]->nightlyRateCop);
    }

    public function testHasOverlapDetectsDateCollisions(): void
    {
        $pdo = $this->createSqlitePdo();
        $pdo->exec("
            INSERT INTO property_rates (id, property_id, start_date, end_date, season_name, price_per_night, min_stay)
            VALUES (1, '1606', '2026-07-01', '2026-07-31', 'July High', 500000, 3)
        ");

        $rateSource = new PdoRateSource($pdo);

        // Exact match
        $this->assertTrue($rateSource->hasOverlap('1606', '2026-07-01', '2026-07-31'));
        // Overlapping start
        $this->assertTrue($rateSource->hasOverlap('1606', '2026-06-25', '2026-07-05'));
        // Overlapping end
        $this->assertTrue($rateSource->hasOverlap('1606', '2026-07-25', '2026-08-05'));
        // Enclosing
        $this->assertTrue($rateSource->hasOverlap('1606', '2026-06-01', '2026-08-31'));
        // Non-overlapping earlier
        $this->assertFalse($rateSource->hasOverlap('1606', '2026-06-01', '2026-06-30'));
        // Non-overlapping later
        $this->assertFalse($rateSource->hasOverlap('1606', '2026-08-01', '2026-08-31'));
        // Different property
        $this->assertFalse($rateSource->hasOverlap('1707', '2026-07-01', '2026-07-31'));
        // Self-exclusion (during update of tier 1)
        $this->assertFalse($rateSource->hasOverlap('1606', '2026-07-01', '2026-07-31', excludeId: 1));
    }

    public function testHasOverlapRejectsInvalidDateRange(): void
    {
        $pdo = $this->createSqlitePdo();
        $rateSource = new PdoRateSource($pdo);

        $this->expectException(InvalidArgumentException::class);
        $rateSource->hasOverlap('1606', '2026-07-31', '2026-07-01');
    }

    public function testSeedFromCsvPopulatesDatabase(): void
    {
        $pdo = $this->createSqlitePdo();
        $rateSource = new PdoRateSource($pdo);

        $tempCsv = tempnam(sys_get_temp_dir(), 'test_rates_');
        file_put_contents(
            $tempCsv,
            "property_id,start_date,end_date,nightly_rate_cop,minimum_stay\n" .
            "1606,2026-01-01,2026-05-31,350000,2\n" .
            "1606,2026-06-01,2026-08-31,450000,3\n"
        );

        $seeded = $rateSource->seedFromCsv($tempCsv);
        unlink($tempCsv);

        $this->assertSame(2, $seeded);
        $tiers = $rateSource->getTiersForProperty('1606');
        $this->assertCount(2, $tiers);
        $this->assertSame(350000.0, $tiers[0]->nightlyRateCop);
        $this->assertSame(450000.0, $tiers[1]->nightlyRateCop);
    }
}
