<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Repository;

use OceanViewFlats\Admin\Repository\AdminAuditLogRepository;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminAuditLogRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AdminAuditLogRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabase();
        $this->repository = new AdminAuditLogRepository($this->pdo);

        // Seed admin users
        $stmtUser = $this->pdo->prepare('
            INSERT INTO admin_users (id, email, password_hash, name, role) 
            VALUES (:id, :email, "hash", :name, :role)
        ');
        $stmtUser->execute(['id' => 1, 'email' => 'admin@test.com', 'name' => 'Alice Admin', 'role' => 'admin']);
        $stmtUser->execute(['id' => 2, 'email' => 'super@test.com', 'name' => 'Bob Super', 'role' => 'superadmin']);

        // Seed audit logs
        $stmtLog = $this->pdo->prepare('
            INSERT INTO admin_audit_logs (id, admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, user_agent, created_at)
            VALUES (:id, :admin_user_id, :action, :entity_type, :entity_id, :payload_before, :payload_after, :ip_address, :user_agent, :created_at)
        ');

        // Log 1: Reservation manual create by Alice
        $stmtLog->execute([
            'id' => 1,
            'admin_user_id' => 1,
            'action' => 'reservation_manual_create',
            'entity_type' => 'reservation',
            'entity_id' => 'res-man-1001',
            'payload_before' => null,
            'payload_after' => json_encode(['property_id' => '1606', 'guest_name' => 'John Doe']),
            'ip_address' => '192.168.1.10',
            'user_agent' => 'Mozilla/5.0 AdminApp',
            'created_at' => '2026-10-01 10:00:00',
        ]);

        // Log 2: Rate tier update by Alice (with state diff)
        $stmtLog->execute([
            'id' => 2,
            'admin_user_id' => 1,
            'action' => 'rate_tier_update',
            'entity_type' => 'property_rates',
            'entity_id' => 'tier-45',
            'payload_before' => json_encode(['nightly_rate' => 120, 'tier_name' => 'High Season']),
            'payload_after' => json_encode(['nightly_rate' => 140, 'tier_name' => 'High Season']),
            'ip_address' => '192.168.1.10',
            'user_agent' => 'Mozilla/5.0 AdminApp',
            'created_at' => '2026-10-02 12:30:00',
        ]);

        // Log 3: Automated webhook settlement by System (admin_user_id is NULL)
        $stmtLog->execute([
            'id' => 3,
            'admin_user_id' => null,
            'action' => 'webhook_settlement',
            'entity_type' => 'reservation',
            'entity_id' => 'res-mp-9999',
            'payload_before' => json_encode(['status' => 'pending']),
            'payload_after' => json_encode(['status' => 'confirmed', 'payment_id' => 'mp_12345']),
            'ip_address' => '200.50.10.5',
            'user_agent' => 'MercadoPago Webhook v2',
            'created_at' => '2026-10-03 15:45:00',
        ]);

        // Log 4: Calendar block created by Bob
        $stmtLog->execute([
            'id' => 4,
            'admin_user_id' => 2,
            'action' => 'calendar_block_create',
            'entity_type' => 'calendar_block',
            'entity_id' => 'block-77',
            'payload_before' => null,
            'payload_after' => json_encode(['property_id' => '1707', 'reason' => 'Plumbing']),
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 SuperUser',
            'created_at' => '2026-10-04 09:15:00',
        ]);
    }

    public function testSearchReturnsAllRecordsByDefault(): void
    {
        $result = $this->repository->search();

        $this->assertSame(4, $result['total']);
        $this->assertCount(4, $result['items']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(AdminAuditLogRepository::DEFAULT_PER_PAGE, $result['per_page']);
        $this->assertSame(1, $result['total_pages']);

        // Check chronological descending order (newest first)
        $this->assertSame(4, $result['items'][0]['id']);
        $this->assertSame('calendar_block_create', $result['items'][0]['action']);
        $this->assertSame(3, $result['items'][1]['id']);
        $this->assertSame(2, $result['items'][2]['id']);
        $this->assertSame(1, $result['items'][3]['id']);
    }

    public function testSearchFiltersByFreeText(): void
    {
        // Search by entity ID
        $res1 = $this->repository->search(['search' => 'res-man-1001']);
        $this->assertSame(1, $res1['total']);
        $this->assertSame('res-man-1001', $res1['items'][0]['entity_id']);

        // Search by IP address
        $res2 = $this->repository->search(['search' => '200.50.10.5']);
        $this->assertSame(1, $res2['total']);
        $this->assertSame('webhook_settlement', $res2['items'][0]['action']);

        // Search by operator name
        $res3 = $this->repository->search(['search' => 'Bob Super']);
        $this->assertSame(1, $res3['total']);
        $this->assertSame('Bob Super', $res3['items'][0]['admin_user_name']);
    }

    public function testSearchFiltersBySpecificAction(): void
    {
        $res = $this->repository->search(['action' => 'rate_tier_update']);
        $this->assertSame(1, $res['total']);
        $this->assertSame(2, $res['items'][0]['id']);
    }

    public function testSearchFiltersByCategory(): void
    {
        // Category 'cat:reservations' includes reservation_manual_create
        $resReservations = $this->repository->search(['action' => 'cat:reservations']);
        $this->assertSame(1, $resReservations['total']);
        $this->assertSame('reservation_manual_create', $resReservations['items'][0]['action']);

        // Category 'cat:system' includes webhook_settlement
        $resSystem = $this->repository->search(['action' => 'cat:system']);
        $this->assertSame(1, $resSystem['total']);
        $this->assertSame('webhook_settlement', $resSystem['items'][0]['action']);
    }

    public function testSearchFiltersByEntityType(): void
    {
        $res = $this->repository->search(['entity_type' => 'reservation']);
        $this->assertSame(2, $res['total']);
    }

    public function testSearchFiltersByActor(): void
    {
        // Filter by System (NULL admin_user_id)
        $systemLogs = $this->repository->search(['actor' => 'system']);
        $this->assertSame(1, $systemLogs['total']);
        $this->assertNull($systemLogs['items'][0]['admin_user_id']);
        $this->assertSame('webhook_settlement', $systemLogs['items'][0]['action']);

        // Filter by Admin User ID 1 (Alice)
        $aliceLogs = $this->repository->search(['actor' => '1']);
        $this->assertSame(2, $aliceLogs['total']);
        $this->assertSame('Alice Admin', $aliceLogs['items'][0]['admin_user_name']);
    }

    public function testSearchFiltersByDateRange(): void
    {
        $res = $this->repository->search([
            'date_from' => '2026-10-02',
            'date_to' => '2026-10-03',
        ]);

        $this->assertSame(2, $res['total']);
        $this->assertSame(3, $res['items'][0]['id']);
        $this->assertSame(2, $res['items'][1]['id']);
    }

    public function testPagination(): void
    {
        $page1 = $this->repository->search(['page' => 1, 'per_page' => 2]);
        $this->assertSame(4, $page1['total']);
        $this->assertSame(2, $page1['total_pages']);
        $this->assertCount(2, $page1['items']);
        $this->assertSame(4, $page1['items'][0]['id']);
        $this->assertSame(3, $page1['items'][1]['id']);

        $page2 = $this->repository->search(['page' => 2, 'per_page' => 2]);
        $this->assertCount(2, $page2['items']);
        $this->assertSame(2, $page2['items'][0]['id']);
        $this->assertSame(1, $page2['items'][1]['id']);
    }

    public function testFindByIdReturnsFullNormalizedRecord(): void
    {
        $log = $this->repository->findById(2);
        $this->assertNotNull($log);
        $this->assertSame(2, $log['id']);
        $this->assertSame('rate_tier_update', $log['action']);
        $this->assertSame('tier-45', $log['entity_id']);
        $this->assertSame('Alice Admin', $log['admin_user_name']);
        $this->assertSame('admin@test.com', $log['admin_user_email']);
        $this->assertSame('admin', $log['admin_user_role']);

        // Payloads should be decoded into arrays
        $this->assertIsArray($log['payload_before']);
        $this->assertSame(120, $log['payload_before']['nightly_rate']);
        $this->assertIsArray($log['payload_after']);
        $this->assertSame(140, $log['payload_after']['nightly_rate']);

        // Raw payloads should be pretty strings
        $this->assertIsString($log['raw_payload_before']);
        $this->assertIsString($log['raw_payload_after']);
    }

    public function testFindByIdReturnsNullForMissingId(): void
    {
        $log = $this->repository->findById(99999);
        $this->assertNull($log);
    }

    public function testGetAdminUsersAndDistinctEntityTypes(): void
    {
        $users = $this->repository->getAdminUsers();
        $this->assertCount(2, $users);
        $this->assertSame('Alice Admin', $users[0]['name']);
        $this->assertSame('Bob Super', $users[1]['name']);

        $types = $this->repository->getDistinctEntityTypes();
        $this->assertContains('reservation', $types);
        $this->assertContains('property_rates', $types);
        $this->assertContains('calendar_block', $types);
    }
}
