<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Domain\Reservation\InMemoryMaintenanceBlockRepository;
use OceanViewFlats\Domain\Reservation\MaintenanceBlock;
use OceanViewFlats\Domain\Reservation\MaintenanceBlockRepositoryInterface;
use OceanViewFlats\Domain\Reservation\PdoMaintenanceBlockRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class MaintenanceBlockRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "editor",
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE calendar_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
            );

            INSERT INTO admin_users (id, name, email, password_hash)
            VALUES (1, "Manuel Admin", "admin@oceanviewflats.com", "hash");
        ');
    }

    /**
     * @return array<string, array{0: callable(PDO): MaintenanceBlockRepositoryInterface}>
     */
    public static function repositoryProvider(): array
    {
        return [
            'PdoRepository' => [
                function (PDO $pdo): MaintenanceBlockRepositoryInterface {
                    return new PdoMaintenanceBlockRepository($pdo);
                },
            ],
            'InMemoryRepository' => [
                function (PDO $pdo): MaintenanceBlockRepositoryInterface {
                    return new InMemoryMaintenanceBlockRepository();
                },
            ],
        ];
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testSaveNewBlockAssignsIdAndPersists(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-11-10',
            endDate: '2026-11-15',
            reason: 'Painting living room',
            createdBy: 1,
            createdByName: 'Manuel Admin'
        );

        $saved = $repo->save($block);

        $this->assertNotNull($saved->id);
        $this->assertGreaterThan(0, $saved->id);
        $this->assertSame('1606', $saved->propertyId);
        $this->assertSame('2026-11-10', $saved->startDate);
        $this->assertSame('2026-11-15', $saved->endDate);
        $this->assertSame('Painting living room', $saved->reason);
        $this->assertSame(1, $saved->createdBy);
        $this->assertNotNull($saved->createdAt);

        // Find by ID verifies persistence
        $found = $repo->findById((int) $saved->id);
        $this->assertNotNull($found);
        $this->assertSame($saved->id, $found->id);
        $this->assertSame('Painting living room', $found->reason);
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testSaveUpdatesExistingBlock(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-11-10',
            endDate: '2026-11-15',
            reason: 'Initial reason',
            createdBy: 1
        );
        $saved = $repo->save($block);
        $id = (int) $saved->id;

        $updatedBlock = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-11-11',
            endDate: '2026-11-16',
            reason: 'Updated reason with AC overhaul',
            id: $id,
            createdBy: 1
        );

        $savedUpdated = $repo->save($updatedBlock);
        $this->assertSame($id, $savedUpdated->id);
        $this->assertSame('2026-11-11', $savedUpdated->startDate);
        $this->assertSame('2026-11-16', $savedUpdated->endDate);
        $this->assertSame('Updated reason with AC overhaul', $savedUpdated->reason);

        $found = $repo->findById($id);
        $this->assertNotNull($found);
        $this->assertSame('Updated reason with AC overhaul', $found->reason);
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testSaveRejectsInvalidPropertyId(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $block = new MaintenanceBlock(
            propertyId: '9999',
            startDate: '2026-11-10',
            endDate: '2026-11-15',
            reason: 'Testing'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid property ID: 9999');

        $repo->save($block);
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testFindByIdReturnsNullForNonExistent(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $this->assertNull($repo->findById(99999));
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testHasOverlapIntervals(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $saved = $repo->save(new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-11-10',
            endDate: '2026-11-15',
            reason: 'Roof work',
            createdBy: 1
        ));
        $id = (int) $saved->id;

        // Overlapping intervals
        $this->assertTrue($repo->hasOverlap('1606', '2026-11-10', '2026-11-15'));
        $this->assertTrue($repo->hasOverlap('1606', '2026-11-12', '2026-11-18'));
        $this->assertTrue($repo->hasOverlap('1606', '2026-11-08', '2026-11-12'));
        $this->assertTrue($repo->hasOverlap('1606', '2026-11-05', '2026-11-20'));

        // Half-open abutting intervals do NOT overlap
        $this->assertFalse($repo->hasOverlap('1606', '2026-11-05', '2026-11-10'));
        $this->assertFalse($repo->hasOverlap('1606', '2026-11-15', '2026-11-20'));

        // Different property does NOT overlap
        $this->assertFalse($repo->hasOverlap('1707', '2026-11-10', '2026-11-15'));

        // Exclude ID works
        $this->assertFalse($repo->hasOverlap('1606', '2026-11-10', '2026-11-15', excludeId: $id));
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testListFilteredWithUpcomingPastAndPropertyFilter(callable $factory): void
    {
        $repo = $factory($this->pdo);
        $referenceNow = new DateTimeImmutable('2026-10-15 12:00:00');

        // Past block: ended 2026-10-10
        $repo->save(new MaintenanceBlock('1606', '2026-10-01', '2026-10-10', 'Past 1606', createdBy: 1));
        // Concluded yesterday: ended 2026-10-14
        $repo->save(new MaintenanceBlock('1707', '2026-10-05', '2026-10-14', 'Past 1707', createdBy: 1));
        // Active block: 2026-10-12 to 2026-10-18 (ends >= today)
        $repo->save(new MaintenanceBlock('1606', '2026-10-12', '2026-10-18', 'Active 1606', createdBy: 1));
        // Future block: 2026-11-01 to 2026-11-05
        $repo->save(new MaintenanceBlock('1606', '2026-11-01', '2026-11-05', 'Future 1606', createdBy: 1));
        // Future block 1707: 2026-11-10 to 2026-11-15
        $repo->save(new MaintenanceBlock('1707', '2026-11-10', '2026-11-15', 'Future 1707', createdBy: 1));

        // 1. Upcoming all: Active 1606, Future 1606, Future 1707 (3 total)
        $upcomingAll = $repo->listFiltered('all', 'upcoming', $referenceNow);
        $this->assertCount(3, $upcomingAll);
        $this->assertSame('Active 1606', $upcomingAll[0]->reason);
        $this->assertSame('Future 1606', $upcomingAll[1]->reason);
        $this->assertSame('Future 1707', $upcomingAll[2]->reason);

        // 2. Upcoming 1606 only: Active 1606, Future 1606 (2 total)
        $upcoming1606 = $repo->listFiltered('1606', 'upcoming', $referenceNow);
        $this->assertCount(2, $upcoming1606);
        $this->assertSame('Active 1606', $upcoming1606[0]->reason);
        $this->assertSame('Future 1606', $upcoming1606[1]->reason);

        // 3. Past all: Past 1707 (start 10-05), Past 1606 (start 10-01) - ordered by start_date DESC
        $pastAll = $repo->listFiltered('all', 'past', $referenceNow);
        $this->assertCount(2, $pastAll);
        $this->assertSame('Past 1707', $pastAll[0]->reason);
        $this->assertSame('Past 1606', $pastAll[1]->reason);

        // 4. All filter for 1606 (ordered DESC by start_date)
        $all1606 = $repo->listFiltered('1606', 'all', $referenceNow);
        $this->assertCount(3, $all1606);
        $this->assertSame('Future 1606', $all1606[0]->reason);
        $this->assertSame('Active 1606', $all1606[1]->reason);
        $this->assertSame('Past 1606', $all1606[2]->reason);
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testDeleteBlock(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $saved = $repo->save(new MaintenanceBlock('1606', '2026-11-10', '2026-11-15', 'ToDelete', createdBy: 1));
        $id = (int) $saved->id;

        $this->assertNotNull($repo->findById($id));
        $this->assertTrue($repo->delete($id));
        $this->assertNull($repo->findById($id));

        // Second delete returns false
        $this->assertFalse($repo->delete($id));
    }

    /**
     * @dataProvider repositoryProvider
     * @param callable(PDO): MaintenanceBlockRepositoryInterface $factory
     */
    public function testGetBlocksAndGetBlockedNights(callable $factory): void
    {
        $repo = $factory($this->pdo);

        $repo->save(new MaintenanceBlock('1606', '2026-11-05', '2026-11-08', 'Block A', createdBy: 1));
        $repo->save(new MaintenanceBlock('1606', '2026-11-15', '2026-11-17', 'Block B', createdBy: 1));
        $repo->save(new MaintenanceBlock('1707', '2026-11-05', '2026-11-10', 'Block C', createdBy: 1));

        $blocks1606 = $repo->getBlocks('1606');
        $this->assertCount(2, $blocks1606);
        $this->assertSame('Block A', $blocks1606[0]->reason);
        $this->assertSame('Block B', $blocks1606[1]->reason);

        $nights1606 = $repo->getBlockedNights('1606');
        $this->assertSame([
            '2026-11-05',
            '2026-11-06',
            '2026-11-07',
            '2026-11-15',
            '2026-11-16',
        ], $nights1606);

        $blocks1707 = $repo->getBlocks('1707');
        $this->assertCount(1, $blocks1707);
        $this->assertSame('Block C', $blocks1707[0]->reason);
    }
}
