<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminAuditLogRoutesTest extends TestCase
{
    private PDO $pdo;
    private AdminApp $app;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabaseWithDefaultAdmin();
        $this->app = AdminApp::createDefault($this->pdo);

        // Seed sample audit log
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_audit_logs (id, admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, user_agent, created_at)
            VALUES (:id, :user_id, :action, :entity_type, :entity_id, :before, :after, :ip, :ua, :created_at)
        ');
        $stmt->execute([
            'id' => 101,
            'user_id' => 1,
            'action' => 'rate_tier_update',
            'entity_type' => 'property_rates',
            'entity_id' => 'tier-101',
            'before' => json_encode(['nightly' => 100]),
            'after' => json_encode(['nightly' => 120]),
            'ip' => '127.0.0.1',
            'ua' => 'AdminBrowser',
            'created_at' => '2026-10-06 11:00:00',
        ]);
    }

    public function testUnauthenticatedRequestToAuditLogsRedirectsToLogin(): void
    {
        $request = new Request('GET', '/audit-logs');
        $session = [];

        $response = $this->app->handle($request, $session);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function testAuthenticatedAdminCanAccessAuditLogs(): void
    {
        $request = new Request('GET', '/audit-logs');
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_role' => 'admin',
            'csrf_token' => 'csrf-valid',
        ];

        $response = $this->app->handle($request, $session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Audit Logs', $response->getBody());
        $this->assertStringContainsString('tier-101', $response->getBody());
    }

    public function testAuthenticatedManagerReceives403Forbidden(): void
    {
        $request = new Request('GET', '/audit-logs');
        $session = [
            'admin_user_id' => 2,
            'admin_user_name' => 'Manager User',
            'admin_user_role' => 'manager',
            'csrf_token' => 'csrf-valid',
        ];

        $response = $this->app->handle($request, $session);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403 - Access Restricted', $response->getBody());
    }

    public function testAuthenticatedAdminCanInspectAuditLogViaHtmx(): void
    {
        $request = new Request(
            'GET',
            '/audit-logs/101',
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_TARGET' => 'drawer-container',
            ]
        );
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_role' => 'admin',
            'csrf_token' => 'csrf-valid',
        ];

        $response = $this->app->handle($request, $session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Audit Log Inspector', $response->getBody());
        $this->assertStringContainsString('#101', $response->getBody());
        $this->assertStringContainsString('tier-101', $response->getBody());
    }

    public function testInspectNonexistentAuditLogReturns404(): void
    {
        $request = new Request('GET', '/audit-logs/99999');
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_role' => 'admin',
            'csrf_token' => 'csrf-valid',
        ];

        $response = $this->app->handle($request, $session);
        $this->assertSame(404, $response->getStatusCode());
    }
}
