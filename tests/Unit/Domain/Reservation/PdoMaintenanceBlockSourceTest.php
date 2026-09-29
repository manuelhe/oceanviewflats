<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use OceanViewFlats\Domain\Reservation\PdoMaintenanceBlockSource;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoMaintenanceBlockSourceTest extends TestCase
{
    private PDO $pdo;
    private PdoMaintenanceBlockSource $source;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE calendar_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->source = new PdoMaintenanceBlockSource($this->pdo);
    }

    public function testGetBlocksAndBlockedNights(): void
    {
        $this->pdo->exec("
            INSERT INTO calendar_blocks (property_id, start_date, end_date, reason, created_by, created_at)
            VALUES 
                ('1606', '2026-11-05', '2026-11-08', 'Balcony maintenance', 1, '2026-10-01 12:00:00'),
                ('1606', '2026-11-15', '2026-11-17', 'Deep cleaning', 1, '2026-10-01 12:00:00'),
                ('1707', '2026-11-05', '2026-11-10', 'Painting', 1, '2026-10-01 12:00:00')
        ");

        $blocks1606 = $this->source->getBlocks('1606');
        $this->assertCount(2, $blocks1606);
        $this->assertSame('Balcony maintenance', $blocks1606[0]->reason);
        $this->assertSame('Deep cleaning', $blocks1606[1]->reason);

        $nights1606 = $this->source->getBlockedNights('1606');
        $this->assertSame([
            '2026-11-05',
            '2026-11-06',
            '2026-11-07',
            '2026-11-15',
            '2026-11-16',
        ], $nights1606);

        $blocks1707 = $this->source->getBlocks('1707');
        $this->assertCount(1, $blocks1707);
        $this->assertSame('Painting', $blocks1707[0]->reason);
    }

    public function testHasOverlapDetection(): void
    {
        $this->pdo->exec("
            INSERT INTO calendar_blocks (id, property_id, start_date, end_date, reason)
            VALUES (1, '1606', '2026-11-10', '2026-11-15', 'Roof maintenance')
        ");

        // Overlapping interval
        $this->assertTrue($this->source->hasOverlap('1606', '2026-11-12', '2026-11-18'));
        $this->assertTrue($this->source->hasOverlap('1606', '2026-11-08', '2026-11-12'));
        $this->assertTrue($this->source->hasOverlap('1606', '2026-11-10', '2026-11-15'));

        // Non-overlapping abutting intervals
        $this->assertFalse($this->source->hasOverlap('1606', '2026-11-05', '2026-11-10'));
        $this->assertFalse($this->source->hasOverlap('1606', '2026-11-15', '2026-11-20'));

        // Different property
        $this->assertFalse($this->source->hasOverlap('1707', '2026-11-10', '2026-11-15'));

        // Exclude current ID
        $this->assertFalse($this->source->hasOverlap('1606', '2026-11-10', '2026-11-15', excludeId: 1));
    }
}
