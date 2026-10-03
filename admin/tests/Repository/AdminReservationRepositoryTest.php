<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Repository;

use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminReservationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminReservationRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE reservations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL UNIQUE,
                property_id TEXT NOT NULL,
                guest_name TEXT NOT NULL,
                guest_email TEXT NOT NULL,
                guest_phone TEXT NOT NULL,
                check_in TEXT NOT NULL,
                check_out TEXT NOT NULL,
                total_price NUMERIC NOT NULL,
                refunded_amount NUMERIC NOT NULL DEFAULT 0.00,
                source TEXT NOT NULL DEFAULT "web",
                external_confirmation_code TEXT DEFAULT NULL,
                channel_block_uid TEXT DEFAULT NULL,
                mercadopago_preference_id TEXT DEFAULT NULL,
                mercadopago_payment_id TEXT DEFAULT NULL,
                payment_status TEXT DEFAULT NULL,
                payment_method_id TEXT DEFAULT NULL,
                payment_detail TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT "pending_payment",
                lang TEXT NOT NULL DEFAULT "en",
                registry_completed INTEGER NOT NULL DEFAULT 0,
                registry_completed_at TEXT DEFAULT NULL,
                door_code TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE guest_registries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL,
                property_id TEXT NOT NULL,
                check_in TEXT NOT NULL,
                check_out TEXT NOT NULL,
                guest_count INTEGER NOT NULL DEFAULT 1,
                guests_payload TEXT NOT NULL,
                car_plates TEXT DEFAULT NULL,
                car_model TEXT DEFAULT NULL,
                ip_address TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "admin",
                is_active INTEGER NOT NULL DEFAULT 1,
                failed_login_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT DEFAULT NULL,
                last_login_at TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE admin_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER DEFAULT NULL,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                payload_before TEXT DEFAULT NULL,
                payload_after TEXT DEFAULT NULL,
                ip_address TEXT NOT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE reservation_refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL,
                mercadopago_refund_id TEXT DEFAULT NULL UNIQUE,
                mercadopago_payment_id TEXT NOT NULL,
                amount NUMERIC NOT NULL,
                status TEXT NOT NULL DEFAULT "approved",
                reason TEXT DEFAULT NULL,
                source TEXT NOT NULL DEFAULT "admin",
                admin_user_id INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        $this->repository = new AdminReservationRepository($this->pdo);
    }

    public function testSearchReservationsReturnsEmptyPaginationWhenNoData(): void
    {
        $emptyPaginatedResult = $this->repository->searchReservations();

        $this->assertCount(0, $emptyPaginatedResult['items']);
        $this->assertSame(0, $emptyPaginatedResult['total']);
        $this->assertSame(1, $emptyPaginatedResult['page']);
        $this->assertSame(25, $emptyPaginatedResult['per_page']);
        $this->assertSame(1, $emptyPaginatedResult['total_pages']);
    }

    public function testSearchReservationsAppliesFiltersCorrectly(): void
    {
        $this->seedSampleReservations();

        // 1. Filter by property_id
        $resultProp = $this->repository->searchReservations(['property_id' => '1606']);
        $this->assertSame(2, $resultProp['total']);
        foreach ($resultProp['items'] as $item) {
            $this->assertSame('1606', $item['property_id']);
        }

        // 2. Filter by status
        $resultStatus = $this->repository->searchReservations(['status' => 'confirmed']);
        $this->assertSame(2, $resultStatus['total']);
        foreach ($resultStatus['items'] as $item) {
            $this->assertSame('confirmed', $item['status']);
        }

        // 3. Filter by registry_status
        $resultRegComp = $this->repository->searchReservations(['registry_status' => 'completed']);
        $this->assertSame(1, $resultRegComp['total']);
        $this->assertSame(1, (int) $resultRegComp['items'][0]['registry_completed']);

        $resultRegPending = $this->repository->searchReservations(['registry_status' => 'pending']);
        $this->assertSame(2, $resultRegPending['total']);

        // 4. Filter by source
        $resultSource = $this->repository->searchReservations(['source' => 'cash']);
        $this->assertSame(1, $resultSource['total']);
        $this->assertSame('cash', $resultSource['items'][0]['source']);

        // 5. Search on guest name, email, phone, or UID
        $resultSearchName = $this->repository->searchReservations(['search' => 'Alice']);
        $this->assertSame(1, $resultSearchName['total']);
        $this->assertSame('Alice Smith', $resultSearchName['items'][0]['guest_name']);

        $resultSearchUid = $this->repository->searchReservations(['search' => 'res-3']);
        $this->assertSame(1, $resultSearchUid['total']);
        $this->assertSame('res-3', $resultSearchUid['items'][0]['reservation_uid']);

        // 6. Date range filtering (check_in_from and check_in_to)
        $resultDate = $this->repository->searchReservations([
            'check_in_from' => '2026-10-10',
            'check_in_to' => '2026-10-15',
        ]);
        $this->assertSame(1, $resultDate['total']);
        $this->assertSame('res-2', $resultDate['items'][0]['reservation_uid']);
    }

    public function testSearchReservationsPaginationAndSorting(): void
    {
        $this->seedSampleReservations();

        // 3 reservations seeded; test limit=1, page=2
        $resultPage2 = $this->repository->searchReservations([
            'limit' => 1,
            'page' => 2,
            'sort_by' => 'created_at',
            'sort_dir' => 'asc',
        ]);

        $this->assertSame(3, $resultPage2['total']);
        $this->assertSame(2, $resultPage2['page']);
        $this->assertSame(1, $resultPage2['per_page']);
        $this->assertSame(3, $resultPage2['total_pages']);
        $this->assertCount(1, $resultPage2['items']);
        $this->assertSame('res-2', $resultPage2['items'][0]['reservation_uid']);

        // Sort by check_in DESC
        $resultSortCheckIn = $this->repository->searchReservations([
            'sort_by' => 'check_in',
            'sort_dir' => 'desc',
        ]);
        $this->assertSame('res-3', $resultSortCheckIn['items'][0]['reservation_uid']);
    }

    public function testFindReservationWithAuditTrailReturnsNullWhenNotFound(): void
    {
        $res = $this->repository->findReservationWithAuditTrail('non-existent-uid');
        $this->assertNull($res);
    }

    public function testFindReservationWithAuditTrailReturnsDetailsAndLogs(): void
    {
        $this->seedSampleReservations();

        // Insert admin user
        $this->pdo->exec("
            INSERT INTO admin_users (id, email, password_hash, name, role)
            VALUES (1, 'admin@oceanviewflats.com', 'dummy_hash', 'Operator Manuel', 'admin');
        ");

        // Insert audit log
        $this->pdo->exec("
            INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, created_at)
            VALUES (1, 'pin_override', 'reservation', 'res-1', '{\"door_code\": \"1111#\"}', '{\"door_code\": \"2222#\"}', '127.0.0.1', '2026-09-29 10:00:00');
        ");

        $res = $this->repository->findReservationWithAuditTrail('res-1');
        $this->assertNotNull($res);
        $this->assertSame('res-1', $res['reservation']['reservation_uid']);
        $this->assertSame('Alice Smith', $res['reservation']['guest_name']);
        $this->assertCount(1, $res['audit_logs']);
        $this->assertSame('pin_override', $res['audit_logs'][0]['action']);
        $this->assertSame('Operator Manuel', $res['audit_logs'][0]['admin_user_name']);
    }

    public function testFindGuestRegistryByReservationUidReturnsDecodedPayload(): void
    {
        $this->seedSampleReservations();

        $payload = json_encode([
            [
                'full_name' => 'Bob Smith',
                'doc_type' => 'CC',
                'doc_number' => '12345678',
                'is_primary' => true,
            ],
            [
                'full_name' => 'Charlie Smith',
                'doc_type' => 'TI',
                'doc_number' => '87654321',
                'is_primary' => false,
            ],
        ]);

        $this->pdo->exec("
            INSERT INTO guest_registries (reservation_uid, property_id, check_in, check_out, guest_count, guests_payload, car_plates, car_model, ip_address)
            VALUES ('res-1', '1606', '2026-10-01', '2026-10-05', 2, '{$payload}', 'ABC-123', 'Toyota Corolla', '192.168.1.1');
        ");

        $registry = $this->repository->findGuestRegistryByReservationUid('res-1');
        $this->assertNotNull($registry);
        $this->assertSame('res-1', $registry['reservation_uid']);
        $this->assertSame('ABC-123', $registry['car_plates']);
        $this->assertIsArray($registry['guests_payload']);
        $this->assertCount(2, $registry['guests_payload']);
        $this->assertSame('Bob Smith', $registry['guests_payload'][0]['full_name']);

        $missing = $this->repository->findGuestRegistryByReservationUid('res-2');
        $this->assertNull($missing);
    }

    public function testFindReservationByUidReturnsRecordOrNull(): void
    {
        $this->seedSampleReservations();

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertSame('res-1', $res['reservation_uid']);
        $this->assertSame('Alice Smith', $res['guest_name']);

        $missing = $this->repository->findReservationByUid('non-existent');
        $this->assertNull($missing);
    }

    public function testUpdateRegistryCompletedUpdatesFlagsAndDoorCode(): void
    {
        $this->seedSampleReservations();

        // Initially res-2 has registry_completed = 0 and door_code = NULL
        $initial = $this->repository->findReservationByUid('res-2');
        $this->assertNotNull($initial);
        $this->assertSame(0, (int) $initial['registry_completed']);
        $this->assertNull($initial['door_code']);

        $updated = $this->repository->updateRegistryCompleted('res-2', '0999888#');
        $this->assertTrue($updated);

        $after = $this->repository->findReservationByUid('res-2');
        $this->assertNotNull($after);
        $this->assertSame(1, (int) $after['registry_completed']);
        $this->assertNotNull($after['registry_completed_at']);
        $this->assertSame('0999888#', $after['door_code']);

        // Test updating without door code
        $updatedAgain = $this->repository->updateRegistryCompleted('res-3');
        $this->assertTrue($updatedAgain);
        $after3 = $this->repository->findReservationByUid('res-3');
        $this->assertNotNull($after3);
        $this->assertSame(1, (int) $after3['registry_completed']);
        $this->assertSame('5678#', $after3['door_code']); // preserves existing code
    }

    public function testUpdateDoorCodeModifiesCode(): void
    {
        $this->seedSampleReservations();

        $success = $this->repository->updateDoorCode('res-1', '0777666#');
        $this->assertTrue($success);

        $record = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($record);
        $this->assertSame('0777666#', $record['door_code']);

        $fail = $this->repository->updateDoorCode('unknown-uid', '0777666#');
        $this->assertFalse($fail);
    }

    public function testCreateManualReservationInsertsAndReturnsUid(): void
    {
        $uid = 'res-manual-123';
        $createdUid = $this->repository->createManualReservation([
            'reservation_uid' => $uid,
            'property_id' => '1707',
            'guest_name' => 'Diana Prince',
            'guest_email' => 'diana@example.com',
            'guest_phone' => '+573009998877',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-06',
            'total_price' => 2500000.00,
            'source' => 'bank_transfer',
            'notes' => 'Confirmed via Bancolombia wire #98765',
            'door_code' => '0888999#',
            'registry_completed' => 1,
            'registry_completed_at' => '2026-09-29 10:00:00',
            'status' => 'confirmed',
            'payment_status' => 'approved',
            'lang' => 'en',
        ]);

        $this->assertSame($uid, $createdUid);

        $record = $this->repository->findReservationByUid($uid);
        $this->assertNotNull($record);
        $this->assertSame('1707', $record['property_id']);
        $this->assertSame('Diana Prince', $record['guest_name']);
        $this->assertSame('bank_transfer', $record['source']);
        $this->assertSame('0888999#', $record['door_code']);
        $this->assertSame(1, (int) $record['registry_completed']);
        $this->assertSame('confirmed', $record['status']);
        $this->assertSame('approved', $record['payment_status']);
    }

    public function testTransactionsCanBeControlled(): void
    {
        $this->assertTrue($this->repository->beginTransaction());
        $this->repository->updateDoorCode('res-1', '0111222#');
        $this->assertTrue($this->repository->rollBack());
    }

    public function testCancelReservationWithFullRefund(): void
    {
        $this->seedSampleReservations();

        $success = $this->repository->cancelReservationWithRefund(
            uid: 'res-1',
            reason: 'Guest requested emergency cancellation',
            refundType: 'full',
            refundAmount: 1200000.00,
            mpRefundId: 'mp-ref-888',
            mpPaymentId: 'mp-pay-111',
            adminUserId: 1
        );

        $this->assertTrue($success);

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertSame('cancelled', $res['status']);
        $this->assertSame('refunded', $res['payment_status']);
        $this->assertEquals(1200000.00, (float) $res['refunded_amount']);
        $this->assertStringContainsString('Guest requested emergency cancellation', (string) $res['notes']);
        $this->assertStringContainsString('Refund: COP 1,200,000.00, type: full', (string) $res['notes']);

        $refunds = $this->repository->findRefundsByReservationUid('res-1');
        $this->assertCount(1, $refunds);
        $this->assertSame('mp-ref-888', $refunds[0]['mercadopago_refund_id']);
        $this->assertSame('mp-pay-111', $refunds[0]['mercadopago_payment_id']);
        $this->assertEquals(1200000.00, (float) $refunds[0]['amount']);
        $this->assertSame('admin_pms', $refunds[0]['source']);

        $detail = $this->repository->findReservationWithAuditTrail('res-1');
        $this->assertNotNull($detail);
        $this->assertCount(1, $detail['refunds']);
    }

    public function testCancelReservationWithPartialRefund(): void
    {
        $this->seedSampleReservations();

        $success = $this->repository->cancelReservationWithRefund(
            uid: 'res-1',
            reason: 'Late cancellation 50% policy retention',
            refundType: 'partial',
            refundAmount: 600000.00,
            mpRefundId: 'mp-ref-partial-1',
            mpPaymentId: 'mp-pay-111',
            adminUserId: null
        );

        $this->assertTrue($success);

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertSame('cancelled', $res['status']);
        $this->assertSame('partially_refunded', $res['payment_status']);
        $this->assertEquals(600000.00, (float) $res['refunded_amount']);
        $this->assertStringContainsString('Refund: COP 600,000.00, type: partial', (string) $res['notes']);
    }

    public function testCancelReservationWithNoRefund(): void
    {
        $this->seedSampleReservations();

        $success = $this->repository->cancelReservationWithRefund(
            uid: 'res-1',
            reason: 'No-show under non-refundable terms',
            refundType: 'none',
            refundAmount: 0.00,
            mpRefundId: null,
            mpPaymentId: null,
            adminUserId: null
        );

        $this->assertTrue($success);

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertSame('cancelled', $res['status']);
        $this->assertEquals(0.00, (float) $res['refunded_amount']);
        $this->assertStringContainsString('Policy retention: No refund', (string) $res['notes']);

        $refunds = $this->repository->findRefundsByReservationUid('res-1');
        $this->assertCount(0, $refunds);
    }

    public function testCancelReservationNonExistentReturnsFalse(): void
    {
        $result = $this->repository->cancelReservationWithRefund(
            uid: 'unknown-uid',
            reason: 'Unknown',
            refundType: 'none',
            refundAmount: 0.0,
            mpRefundId: null,
            mpPaymentId: null,
            adminUserId: null
        );

        $this->assertFalse($result);
    }

    private function seedSampleReservations(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, source, registry_completed,
                door_code, created_at
            ) VALUES 
            ('res-1', '1606', 'Alice Smith', 'alice@example.com', '+573001112233', '2026-10-01', '2026-10-05', 1200000.00, 'confirmed', 'web', 1, '1234#', '2026-09-01 12:00:00'),
            ('res-2', '1606', 'Bob Jones', 'bob@example.com', '+573004445566', '2026-10-10', '2026-10-15', 1500000.00, 'pending_payment', 'cash', 0, NULL, '2026-09-02 12:00:00'),
            ('res-3', '1707', 'Carlos Gomez', 'carlos@example.com', '+573007778899', '2026-10-20', '2026-10-25', 1800000.00, 'confirmed', 'manual_override', 0, '5678#', '2026-09-03 12:00:00');
        ");
    }
}
