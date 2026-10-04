<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Repository;

use OceanViewFlats\Admin\Repository\AdminRateRepository;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminRateRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminRateRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE
            );

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
            );

            INSERT INTO admin_users (id, name, email) VALUES (1, "Manuel Admin", "admin@oceanviewflats.com");
        ');

        $this->repository = new AdminRateRepository($this->pdo, PropertyRatesConfig::createDefault());
    }

    public function testCreateRateAndFindById(): void
    {
        $id = $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'season_name' => 'Summer Holiday',
            'price_per_night' => 450000.0,
            'min_stay' => 3,
        ], adminUserId: 1);

        $this->assertGreaterThan(0, $id);

        $rate = $this->repository->findRateById($id);
        $this->assertNotNull($rate);
        $this->assertSame('1606', $rate['property_id']);
        $this->assertSame('Summer Holiday', $rate['season_name']);
        $this->assertSame('2026-06-01', $rate['start_date']);
        $this->assertSame('2026-08-31', $rate['end_date']);
        $this->assertEquals(450000.0, (float) $rate['price_per_night']);
        $this->assertEquals(3, (int) $rate['min_stay']);
        $this->assertSame('Manuel Admin', $rate['created_by_name']);
    }

    public function testFindRateByIdReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->repository->findRateById(9999));
    }

    public function testUpdateRate(): void
    {
        $id = $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'season_name' => 'Old Season',
            'price_per_night' => 400000.0,
            'min_stay' => 2,
        ], adminUserId: 1);

        $updated = $this->repository->updateRate($id, [
            'season_name' => 'Updated Season',
            'start_date' => '2026-06-15',
            'end_date' => '2026-08-15',
            'price_per_night' => 520000.0,
            'min_stay' => 4,
        ]);

        $this->assertTrue($updated);

        $rate = $this->repository->findRateById($id);
        $this->assertNotNull($rate);
        $this->assertSame('Updated Season', $rate['season_name']);
        $this->assertSame('2026-06-15', $rate['start_date']);
        $this->assertSame('2026-08-15', $rate['end_date']);
        $this->assertEquals(520000.0, (float) $rate['price_per_night']);
        $this->assertEquals(4, (int) $rate['min_stay']);
    }

    public function testDeleteRate(): void
    {
        $id = $this->repository->createRate([
            'property_id' => '1707',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'season_name' => 'Autumn Promo',
            'price_per_night' => 380000.0,
            'min_stay' => 2,
        ]);

        $this->assertNotNull($this->repository->findRateById($id));
        $deleted = $this->repository->deleteRate($id);
        $this->assertTrue($deleted);
        $this->assertNull($this->repository->findRateById($id));
    }

    public function testGetRatesForPropertyWithYearFilter(): void
    {
        // 2025 tier
        $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2025-11-01',
            'end_date' => '2025-12-15',
            'season_name' => '2025 Early Low',
            'price_per_night' => 320000.0,
            'min_stay' => 2,
        ]);

        // 2026 tier 1
        $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-30',
            'season_name' => '2026 Standard',
            'price_per_night' => 350000.0,
            'min_stay' => 2,
        ]);

        // 2026 tier 2 (overlapping into 2027)
        $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2026-12-15',
            'end_date' => '2027-01-15',
            'season_name' => '2026-2027 Holiday High',
            'price_per_night' => 550000.0,
            'min_stay' => 4,
        ]);

        // 1707 tier (should be ignored)
        $this->repository->createRate([
            'property_id' => '1707',
            'start_date' => '2026-02-01',
            'end_date' => '2026-05-31',
            'season_name' => '1707 Spring',
            'price_per_night' => 450000.0,
            'min_stay' => 2,
        ]);

        $allRates1606 = $this->repository->getRatesForProperty('1606');
        $this->assertCount(3, $allRates1606);

        $rates2026 = $this->repository->getRatesForProperty('1606', 2026);
        $this->assertCount(2, $rates2026);
        $this->assertSame('2026 Standard', $rates2026[0]['season_name']);
        $this->assertSame('2026-2027 Holiday High', $rates2026[1]['season_name']);
    }

    public function testDetectGapsWhenNoTiersExistInYear(): void
    {
        $gaps = $this->repository->detectGaps('1606', 2026);
        $this->assertCount(1, $gaps);
        $this->assertSame('2026-01-01', $gaps[0]['start_date']);
        $this->assertSame('2026-12-31', $gaps[0]['end_date']);
        $this->assertSame(365, $gaps[0]['nights']);
        $this->assertSame(350000.0, $gaps[0]['fallback_rate']);
    }

    public function testDetectGapsWithSingleTierInMiddleOfYear(): void
    {
        $this->repository->createRate([
            'property_id' => '1707',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'season_name' => 'Summer Peak',
            'price_per_night' => 500000.0,
            'min_stay' => 3,
        ]);

        $gaps = $this->repository->detectGaps('1707', 2026);
        $this->assertCount(2, $gaps);

        // Gap 1: Jan 1 to May 31
        $this->assertSame('2026-01-01', $gaps[0]['start_date']);
        $this->assertSame('2026-05-31', $gaps[0]['end_date']);
        $this->assertSame(151, $gaps[0]['nights']);
        $this->assertSame(450000.0, $gaps[0]['fallback_rate']);

        // Gap 2: Sep 1 to Dec 31
        $this->assertSame('2026-09-01', $gaps[1]['start_date']);
        $this->assertSame('2026-12-31', $gaps[1]['end_date']);
        $this->assertSame(122, $gaps[1]['nights']);
        $this->assertSame(450000.0, $gaps[1]['fallback_rate']);
    }

    public function testDetectGapsWhenYearIsFullyCovered(): void
    {
        $this->repository->createRate([
            'property_id' => '1606',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'season_name' => 'Full Year',
            'price_per_night' => 350000.0,
            'min_stay' => 2,
        ]);

        $gaps = $this->repository->detectGaps('1606', 2026);
        $this->assertEmpty($gaps);
    }

    public function testPropertyRateStatus(): void
    {
        $this->repository->createRate([
            'property_id' => '1707',
            'start_date' => '2026-06-01',
            'end_date' => '2026-08-31',
            'season_name' => 'Mid Year Peak',
            'price_per_night' => 520000.0,
            'min_stay' => 3,
        ]);

        $status = $this->repository->getPropertyRateStatus('1707', new \DateTimeImmutable('2026-07-01'));
        $this->assertSame(520000.0, $status->currentNightlyRate);
        $this->assertTrue($status->isSeasonalTierActive);
        $this->assertSame('Mid Year Peak', $status->activeTierName);
        $this->assertSame('2026-08-31', $status->activeTierEndDate);
        $this->assertSame(450000.0, $status->nextTierRate);
        $this->assertSame('Baseline Rate', $status->nextTierName);
        $this->assertSame('2026-09-01', $status->nextTierStartDate);
    }
}
