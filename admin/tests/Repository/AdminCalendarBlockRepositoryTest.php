<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminCalendarBlockRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminCalendarBlockRepository $repository;

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

            CREATE TABLE calendar_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            INSERT INTO admin_users (id, name, email) VALUES (1, "Manuel Admin", "admin@oceanviewflats.com");
        ');

        $this->repository = new AdminCalendarBlockRepository($this->pdo);
    }

    public function testCreateBlockAndFindById(): void
    {
        $id = $this->repository->createBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Tile repair and painting',
            createdBy: 1
        );

        $this->assertGreaterThan(0, $id);

        $block = $this->repository->findById($id);
        $this->assertNotNull($block);
        $this->assertSame('1606', $block['property_id']);
        $this->assertSame('2026-10-10', $block['start_date']);
        $this->assertSame('2026-10-15', $block['end_date']);
        $this->assertSame('Tile repair and painting', $block['reason']);
        $this->assertSame('Manuel Admin', $block['created_by_name']);
    }

    public function testCreateBlockValidations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid property ID');
        $this->repository->createBlock('9999', '2026-10-10', '2026-10-15', 'Reason', 1);
    }

    public function testCreateBlockRejectsEndBeforeStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('End date (2026-10-09) must be after start date (2026-10-10)');
        $this->repository->createBlock('1606', '2026-10-10', '2026-10-09', 'Reason', 1);
    }

    public function testCreateBlockRejectsEmptyReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason cannot be empty');
        $this->repository->createBlock('1606', '2026-10-10', '2026-10-15', '   ', 1);
    }

    public function testHasOverlapDetection(): void
    {
        $id = $this->repository->createBlock('1606', '2026-11-01', '2026-11-05', 'Plumbing', 1);

        $this->assertTrue($this->repository->hasOverlap('1606', '2026-11-02', '2026-11-04'));
        $this->assertTrue($this->repository->hasOverlap('1606', '2026-10-28', '2026-11-02'));
        $this->assertTrue($this->repository->hasOverlap('1606', '2026-11-04', '2026-11-10'));

        // Abutting check-in / check-out dates do NOT overlap
        $this->assertFalse($this->repository->hasOverlap('1606', '2026-10-25', '2026-11-01'));
        $this->assertFalse($this->repository->hasOverlap('1606', '2026-11-05', '2026-11-10'));

        // Different property
        $this->assertFalse($this->repository->hasOverlap('1707', '2026-11-01', '2026-11-05'));

        // Exclude self ID
        $this->assertFalse($this->repository->hasOverlap('1606', '2026-11-01', '2026-11-05', excludeId: $id));
    }

    public function testGetBlocksFilteringByPropertyAndStatus(): void
    {
        $now = new DateTimeImmutable('2026-10-01');

        // Past block: ended Sep 20
        $this->repository->createBlock('1606', '2026-09-10', '2026-09-20', 'Past 1606', 1);
        // Active/upcoming block: starts Oct 05, ends Oct 10
        $this->repository->createBlock('1606', '2026-10-05', '2026-10-10', 'Upcoming 1606', 1);
        // Upcoming block on 1707: starts Oct 15, ends Oct 20
        $this->repository->createBlock('1707', '2026-10-15', '2026-10-20', 'Upcoming 1707', 1);

        // Upcoming all
        $upcomingAll = $this->repository->getBlocks('all', 'upcoming', $now);
        $this->assertCount(2, $upcomingAll);

        // Upcoming 1606
        $upcoming1606 = $this->repository->getBlocks('1606', 'upcoming', $now);
        $this->assertCount(1, $upcoming1606);
        $this->assertSame('Upcoming 1606', $upcoming1606[0]['reason']);

        // Past all
        $pastAll = $this->repository->getBlocks('all', 'past', $now);
        $this->assertCount(1, $pastAll);
        $this->assertSame('Past 1606', $pastAll[0]['reason']);

        // All for property 1606
        $all1606 = $this->repository->getBlocks('1606', 'all', $now);
        $this->assertCount(2, $all1606);
    }

    public function testDeleteBlock(): void
    {
        $id = $this->repository->createBlock('1707', '2026-12-01', '2026-12-05', 'Balcony varnish', 1);

        $this->assertTrue($this->repository->deleteBlock($id));
        $this->assertNull($this->repository->findById($id));

        // Deleting non-existent block returns false
        $this->assertFalse($this->repository->deleteBlock($id));
    }
}
