<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Controller\AuditLogController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Repository\AdminAuditLogRepository;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLogControllerTest extends TestCase
{
    private PDO $pdo;
    private AdminAuditLogRepository $repository;
    private ViewRenderer $viewRenderer;
    private AuditLogController $controller;

    /**
     * @var array<string, mixed>
     */
    private array $adminSession;

    /**
     * @var array<string, mixed>
     */
    private array $managerSession;

    /**
     * @var array<string, mixed>
     */
    private array $viewerSession;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabase();
        $this->repository = new AdminAuditLogRepository($this->pdo);
        $this->viewRenderer = new ViewRenderer(dirname(__DIR__, 2) . '/src/Views');
        $this->controller = new AuditLogController($this->repository, $this->viewRenderer);

        // Seed admin user
        $this->pdo->exec('
            INSERT INTO admin_users (id, email, password_hash, name, role) 
            VALUES 
                (1, "admin@test.com", "hash", "Admin Alice", "admin"),
                (2, "manager@test.com", "hash", "Manager Bob", "manager"),
                (3, "viewer@test.com", "hash", "Viewer Charlie", "viewer"),
                (4, "super@test.com", "hash", "Super Dave", "superadmin");
        ');

        // Seed logs
        $stmtLog = $this->pdo->prepare('
            INSERT INTO admin_audit_logs (id, admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, user_agent, created_at)
            VALUES (:id, :admin_user_id, :action, :entity_type, :entity_id, :payload_before, :payload_after, :ip_address, :user_agent, :created_at)
        ');

        $stmtLog->execute([
            'id' => 10,
            'admin_user_id' => 1,
            'action' => 'door_code_override',
            'entity_type' => 'reservation',
            'entity_id' => 'res-man-2002',
            'payload_before' => json_encode(['door_code' => '1234']),
            'payload_after' => json_encode(['door_code' => '5678']),
            'ip_address' => '181.50.20.10',
            'user_agent' => 'Admin Chrome on macOS',
            'created_at' => '2026-10-05 14:00:00',
        ]);

        $this->adminSession = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Admin Alice',
            'admin_user_email' => 'admin@test.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'valid-csrf-token',
        ];

        $this->managerSession = [
            'admin_user_id' => 2,
            'admin_user_name' => 'Manager Bob',
            'admin_user_email' => 'manager@test.com',
            'admin_user_role' => 'manager',
            'csrf_token' => 'valid-csrf-token',
        ];

        $this->viewerSession = [
            'admin_user_id' => 3,
            'admin_user_name' => 'Viewer Charlie',
            'admin_user_email' => 'viewer@test.com',
            'admin_user_role' => 'viewer',
            'csrf_token' => 'valid-csrf-token',
        ];
    }

    public function testIndexRendersFullPageForAdminRole(): void
    {
        $request = new Request('GET', '/audit-logs');
        $response = $this->controller->index($request, $this->adminSession);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        $this->assertStringContainsString('Audit Logs', $body);
        $this->assertStringContainsString('Immutable Audit Trail Active', $body);
        $this->assertStringContainsString('res-man-2002', $body);
        $this->assertStringContainsString('Door Code Override', $body);
        $this->assertStringContainsString('Admin Alice', $body);
        $this->assertStringContainsString('id="audit-table-container"', $body);
        $this->assertStringContainsString('id="drawer-container"', $body);
    }

    public function testIndexRendersFullPageForSuperadminRole(): void
    {
        $superSession = [
            'admin_user_id' => 4,
            'admin_user_name' => 'Super Dave',
            'admin_user_role' => 'superadmin',
        ];
        $request = new Request('GET', '/audit-logs');
        $response = $this->controller->index($request, $superSession);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Audit Logs', $response->getBody());
    }

    public function testIndexReturns403ForManagerAndViewerRoles(): void
    {
        $request = new Request('GET', '/audit-logs');

        // Manager
        $respManager = $this->controller->index($request, $this->managerSession);
        $this->assertSame(403, $respManager->getStatusCode());
        $this->assertStringContainsString('403 - Access Restricted', $respManager->getBody());
        $this->assertStringContainsString('manager', $respManager->getBody());

        // Viewer
        $respViewer = $this->controller->index($request, $this->viewerSession);
        $this->assertSame(403, $respViewer->getStatusCode());
        $this->assertStringContainsString('403 - Access Restricted', $respViewer->getBody());
        $this->assertStringContainsString('viewer', $respViewer->getBody());
    }

    public function testIndexHtmxForbiddenReturns403Snippet(): void
    {
        $request = new Request('GET', '/audit-logs', server: [
            'HTTP_HX_REQUEST' => 'true',
            'HTTP_HX_TARGET' => 'audit-table-container',
        ]);
        $response = $this->controller->index($request, $this->managerSession);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Access Restricted', $response->getBody());
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getBody());
    }

    public function testIndexHtmxTargetTableReturnsPartialOnly(): void
    {
        $request = new Request('GET', '/audit-logs', server: [
            'HTTP_HX_REQUEST' => 'true',
            'HTTP_HX_TARGET' => 'audit-table-container',
        ]);
        $response = $this->controller->index($request, $this->adminSession);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        // Should contain table rows and pagination, but NOT the outer header/search toolbar
        $this->assertStringContainsString('res-man-2002', $body);
        $this->assertStringContainsString('Showing', $body);
        $this->assertStringNotContainsString('id="audit-filter-form"', $body);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $body);
    }

    public function testShowRendersDrawerPartialWhenHtmxTargetDrawerContainer(): void
    {
        $request = new Request(
            'GET',
            '/audit-logs/10',
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_TARGET' => 'drawer-container',
            ],
            attributes: ['id' => 10]
        );
        $response = $this->controller->show($request, $this->adminSession);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        $this->assertStringContainsString('Audit Log Inspector', $body);
        $this->assertStringContainsString('door_code_override', $body);
        $this->assertStringContainsString('res-man-2002', $body);
        $this->assertStringContainsString('/reservations?search=res-man-2002', $body);
        $this->assertStringContainsString('181.50.20.10', $body);

        // State transition diff assertions
        $this->assertStringContainsString('door_code', $body);
        $this->assertStringContainsString('1234', $body);
        $this->assertStringContainsString('5678', $body);
        $this->assertStringContainsString('(Modified)', $body);

        // Raw JSON payloads
        $this->assertStringContainsString('Raw JSON Payloads', $body);
        $this->assertStringContainsString('btn-copy-before', $body);
        $this->assertStringContainsString('btn-copy-after', $body);
    }

    public function testShowReturns404WhenLogNotFound(): void
    {
        $requestHtmx = new Request(
            'GET',
            '/audit-logs/9999',
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_TARGET' => 'drawer-container',
            ],
            attributes: ['id' => 9999]
        );
        $respHtmx = $this->controller->show($requestHtmx, $this->adminSession);
        $this->assertSame(404, $respHtmx->getStatusCode());
        $this->assertStringContainsString('Audit log not found', $respHtmx->getBody());

        $requestDirect = new Request('GET', '/audit-logs/9999', attributes: ['id' => 9999]);
        $respDirect = $this->controller->show($requestDirect, $this->adminSession);
        $this->assertSame(404, $respDirect->getStatusCode());
    }

    public function testShowReturns403ForUnauthorizedRole(): void
    {
        $request = new Request('GET', '/audit-logs/10', attributes: ['id' => 10]);
        $response = $this->controller->show($request, $this->managerSession);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403 - Access Restricted', $response->getBody());
    }
}
