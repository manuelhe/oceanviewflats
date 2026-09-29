<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Audit;

use OceanViewFlats\Admin\Audit\AuditLogger;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL
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
        ');

        $this->pdo->exec('
            INSERT INTO admin_users (id, email, password_hash, name)
            VALUES (1, "operator@oceanviewflats.com", "hash", "Operator")
        ');
    }

    public function testLogsActionWithBeforeAndAfterPayloads(): void
    {
        $logId = AuditLogger::log(
            pdo: $this->pdo,
            action: 'pin_override',
            entityType: 'reservation',
            entityId: 'res_test_12345',
            before: ['door_code' => '0160600#'],
            after: ['door_code' => '0999888#'],
            adminUserId: 1,
            ipAddress: '192.168.1.100',
            userAgent: 'Mozilla/5.0 AdminBrowser'
        );

        $this->assertGreaterThan(0, $logId);

        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE id = :id');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch();

        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['admin_user_id']);
        $this->assertSame('pin_override', $row['action']);
        $this->assertSame('reservation', $row['entity_type']);
        $this->assertSame('res_test_12345', $row['entity_id']);
        $this->assertSame('192.168.1.100', $row['ip_address']);
        $this->assertSame('Mozilla/5.0 AdminBrowser', $row['user_agent']);

        $before = json_decode((string) $row['payload_before'], true);
        $after = json_decode((string) $row['payload_after'], true);
        $this->assertSame(['door_code' => '0160600#'], $before);
        $this->assertSame(['door_code' => '0999888#'], $after);
    }

    public function testLogsActionWithNullPayloadsAndNullAdminUser(): void
    {
        $logId = AuditLogger::log(
            pdo: $this->pdo,
            action: 'login_failure',
            entityType: 'auth',
            entityId: 'admin@oceanviewflats.com',
            before: null,
            after: null,
            adminUserId: null,
            ipAddress: '203.0.113.5'
        );

        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE id = :id');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch();

        $this->assertIsArray($row);
        $this->assertNull($row['admin_user_id']);
        $this->assertNull($row['payload_before']);
        $this->assertNull($row['payload_after']);
        $this->assertSame('203.0.113.5', $row['ip_address']);
    }

    public function testRetrievesLogsForSpecificEntity(): void
    {
        AuditLogger::log(pdo: $this->pdo, action: 'create', entityType: 'reservation', entityId: 'res_A', before: null, after: ['status' => 'confirmed'], adminUserId: 1);
        AuditLogger::log(pdo: $this->pdo, action: 'modify', entityType: 'reservation', entityId: 'res_A', before: ['status' => 'confirmed'], after: ['status' => 'cancelled'], adminUserId: 1);
        AuditLogger::log(pdo: $this->pdo, action: 'create', entityType: 'reservation', entityId: 'res_B', before: null, after: ['status' => 'confirmed'], adminUserId: 1);

        $logs = AuditLogger::getLogsForEntity($this->pdo, 'reservation', 'res_A');
        $this->assertCount(2, $logs);
        $this->assertSame('create', $logs[0]['action']);
        $this->assertSame('modify', $logs[1]['action']);
    }

    public function testLogsActionUsingDefaultPdoWithoutPassingPdoParameter(): void
    {
        AuditLogger::setDefaultPdo($this->pdo);

        // Spec signature without pdo parameter
        $logId = AuditLogger::log(
            action: 'pin_override',
            entityType: 'reservation',
            entityId: 'res_default_pdo',
            before: ['door_code' => '1234#'],
            after: ['door_code' => '5678#'],
            adminUserId: 1
        );

        $this->assertGreaterThan(0, $logId);

        $stmt = $this->pdo->prepare('SELECT action, entity_id FROM admin_audit_logs WHERE id = :id');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame('pin_override', $row['action']);
        $this->assertSame('res_default_pdo', $row['entity_id']);
    }

    public function testInjectableServiceInstanceRecordsAction(): void
    {
        $logger = new AuditLogger($this->pdo);

        $logId = $logger->record(
            action: 'refund_issued',
            entityType: 'payment',
            entityId: 'pay_999',
            before: ['status' => 'captured'],
            after: ['status' => 'refunded'],
            adminUserId: 1
        );

        $this->assertGreaterThan(0, $logId);

        $stmt = $this->pdo->prepare('SELECT action, entity_id FROM admin_audit_logs WHERE id = :id');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame('refund_issued', $row['action']);
        $this->assertSame('pay_999', $row['entity_id']);
    }
}
