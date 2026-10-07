<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use OceanViewFlats\Domain\Database\MigrationRunner;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearance;
use OceanViewFlats\Domain\Fulfillment\PdoCondominiumClearanceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoCondominiumClearanceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoCondominiumClearanceRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        MigrationRunner::run($this->pdo, 'test_db');
        $this->repository = new PdoCondominiumClearanceRepository($this->pdo);
    }

    public function testSaveAndFindByReservationUid(): void
    {
        $clearance = CondominiumClearance::createPending(
            reservationUid: 'ovf_res_001',
            propertyId: '1707',
            requestPayload: ['step1' => ['apt' => '1707']]
        );

        $this->repository->save($clearance);

        $retrieved = $this->repository->findByReservationUid('ovf_res_001');
        $this->assertNotNull($retrieved);
        $this->assertSame('ovf_res_001', $retrieved->reservationUid);
        $this->assertSame('1707', $retrieved->propertyId);
        $this->assertSame(CondominiumClearance::STATUS_PENDING, $retrieved->status);
        $this->assertSame(['step1' => ['apt' => '1707']], $retrieved->requestPayload);
        $this->assertSame(1, $retrieved->attempts);
        $this->assertNull($retrieved->clearanceNumber);
        $this->assertNull($retrieved->errorMessage);
    }

    public function testSaveUpsertUpdatesExistingRecord(): void
    {
        $clearance = CondominiumClearance::createPending(
            reservationUid: 'ovf_res_002',
            propertyId: '1606',
            requestPayload: ['step1' => ['apt' => '1606']]
        );
        $this->repository->save($clearance);

        // Update to synced
        $synced = $clearance->markSynced('499', '2026-10-07 15:30:00');
        $this->repository->save($synced);

        $retrieved = $this->repository->findByReservationUid('ovf_res_002');
        $this->assertNotNull($retrieved);
        $this->assertSame(CondominiumClearance::STATUS_SYNCED, $retrieved->status);
        $this->assertSame('499', $retrieved->clearanceNumber);
        $this->assertSame('2026-10-07 15:30:00', $retrieved->syncedAt);
        $this->assertNull($retrieved->errorMessage);

        // Update to failed on retry
        $failed = $synced->recordAttempt(['retry' => 1])->markFailed('Portal connection timed out');
        $this->repository->save($failed);

        $retrievedFailed = $this->repository->findByReservationUid('ovf_res_002');
        $this->assertNotNull($retrievedFailed);
        $this->assertSame(CondominiumClearance::STATUS_FAILED, $retrievedFailed->status);
        $this->assertSame('Portal connection timed out', $retrievedFailed->errorMessage);
        $this->assertSame(2, $retrievedFailed->attempts);
        $this->assertSame(['retry' => 1], $retrievedFailed->requestPayload);
    }

    public function testFindByReservationUidReturnsNullWhenNotFound(): void
    {
        $result = $this->repository->findByReservationUid('non_existent_uid');
        $this->assertNull($result);
    }

    public function testFindFailedClearancesFiltersAndLimits(): void
    {
        // 1. Synced clearance
        $c1 = CondominiumClearance::createPending('res_synced', '1707')->markSynced('100');
        $this->repository->save($c1);

        // 2. Failed clearance 1
        $c2 = CondominiumClearance::createPending('res_failed_1', '1707', null, '2026-10-07 10:00:00')
            ->markFailed('HTTP 500 error', '2026-10-07 10:00:00');
        $this->repository->save($c2);

        // 3. Failed clearance 2 (more recent)
        $c3 = CondominiumClearance::createPending('res_failed_2', '1606', null, '2026-10-07 11:00:00')
            ->markFailed('Timeout error', '2026-10-07 11:00:00');
        $this->repository->save($c3);

        // 4. Pending clearance
        $c4 = CondominiumClearance::createPending('res_pending', '1707');
        $this->repository->save($c4);

        $failed = $this->repository->findFailedClearances(10);
        $this->assertCount(2, $failed);
        $this->assertSame('res_failed_2', $failed[0]->reservationUid);
        $this->assertSame('res_failed_1', $failed[1]->reservationUid);

        // Test limit
        $limited = $this->repository->findFailedClearances(1);
        $this->assertCount(1, $limited);
        $this->assertSame('res_failed_2', $limited[0]->reservationUid);
    }
}
