<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminFrontControllerTest extends TestCase
{
    private PDO $pdo;
    private InMemoryIpRateLimiter $rateLimiter;
    private AuthService $authService;
    private ViewRenderer $viewRenderer;
    private Router $router;

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
        ');

        $this->rateLimiter = new InMemoryIpRateLimiter(maxAttempts: 10, windowSeconds: 900);
        $this->authService = new AuthService($this->pdo, $this->rateLimiter);

        $viewsPath = dirname(__DIR__, 2) . '/src/Views';
        $this->viewRenderer = new ViewRenderer($viewsPath);

        $sessionMiddleware = new SessionMiddleware();
        $csrfMiddleware = new CsrfMiddleware();
        $authMiddleware = new AuthMiddleware();

        $this->router = new Router(
            pdo: $this->pdo,
            authService: $this->authService,
            viewRenderer: $this->viewRenderer,
            sessionMiddleware: $sessionMiddleware,
            csrfMiddleware: $csrfMiddleware,
            authMiddleware: $authMiddleware
        );
    }

    private function createAdminUser(string $email, string $password, string $name = 'Super Admin'): int
    {
        $hash = $this->authService->hashPassword($password);
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_users (email, password_hash, name, role, is_active)
            VALUES (:email, :hash, :name, "admin", 1)
        ');
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'hash' => $hash,
            'name' => $name,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testGetLoginRendersPageAndGeneratesCsrfToken(): void
    {
        $session = [];
        $request = new Request('GET', '/login');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($session['csrf_token']);
        $this->assertStringContainsString('Ocean View Flats Admin', $response->getBody());
        $this->assertStringContainsString((string) $session['csrf_token'], $response->getBody());
    }

    public function testGetLoginRedirectsToDashboardIfAlreadyAuthenticated(): void
    {
        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/login');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location']);
    }

    public function testPostLoginFailsWithoutCsrfToken(): void
    {
        $session = ['csrf_token' => 'token_secret_123'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: ['email' => 'admin@test.com', 'password' => 'secret']
        );

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('403 Forbidden', $response->getBody());
    }

    public function testPostLoginWithInvalidPasswordFailsAndLogsAudit(): void
    {
        $this->createAdminUser('admin@oceanviewflats.com', 'CorrectPass123!');
        $session = ['csrf_token' => 'valid_csrf_token'];

        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'csrf_token' => 'valid_csrf_token',
                'email' => 'admin@oceanviewflats.com',
                'password' => 'WrongPassword',
            ],
            server: ['REMOTE_ADDR' => '192.168.1.5']
        );

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('The email address or password entered is incorrect.', $response->getBody());
        $this->assertArrayNotHasKey('admin_user_id', $session);

        // Verify audit log entry
        $stmt = $this->pdo->query('SELECT action, entity_type, entity_id FROM admin_audit_logs');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame('login_failure', $log['action']);
        $this->assertSame('auth', $log['entity_type']);
        $this->assertSame('admin@oceanviewflats.com', $log['entity_id']);
    }

    public function testPostLoginSuccessEstablishesSessionAndRedirects(): void
    {
        $userId = $this->createAdminUser('manager@oceanviewflats.com', 'SecretPassword123!', 'Alice Manager');
        $session = ['csrf_token' => 'valid_csrf_token'];

        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'csrf_token' => 'valid_csrf_token',
                'email' => 'manager@oceanviewflats.com',
                'password' => 'SecretPassword123!',
            ],
            server: ['REMOTE_ADDR' => '10.0.0.99', 'HTTP_USER_AGENT' => 'TestBrowser/1.0']
        );

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location']);

        $this->assertSame($userId, $session['admin_user_id']);
        $this->assertSame('Alice Manager', $session['admin_user_name']);
        $this->assertSame('manager@oceanviewflats.com', $session['admin_user_email']);
        $this->assertSame('admin', $session['admin_user_role']);

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT action, entity_type, entity_id, admin_user_id FROM admin_audit_logs WHERE action = "login_success"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame((string) $userId, $log['entity_id']);
        $this->assertSame($userId, (int) $log['admin_user_id']);
    }

    public function testProtectedDashboardGatedByAuthMiddleware(): void
    {
        $session = []; // Unauthenticated
        $request = new Request('GET', '/');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testProtectedDashboardRendersForAuthenticatedUser(): void
    {
        $session = [
            'admin_user_id' => 99,
            'admin_user_name' => 'Operator Bob',
            'admin_user_email' => 'bob@oceanviewflats.com',
            'admin_user_role' => 'admin',
        ];
        $request = new Request('GET', '/');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Welcome back, Operator Bob', $response->getBody());
        $this->assertStringContainsString('Sign Out', $response->getBody());
    }

    public function testLogoutClearsSessionAndRedirects(): void
    {
        $session = [
            'admin_user_id' => 99,
            'admin_user_name' => 'Operator Bob',
        ];
        $request = new Request('GET', '/logout');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?reason=logged_out', $response->getHeaders()['Location']);
        $this->assertEmpty($session);

        // Verify audit log
        $stmt = $this->pdo->query('SELECT action, entity_id, admin_user_id FROM admin_audit_logs WHERE action = "logout"');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame('99', $log['entity_id']);
    }

    public function testNotFoundReturns404(): void
    {
        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/non-existent-page');

        $response = $this->router->dispatch($request, $session);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', $response->getBody());
    }
}
