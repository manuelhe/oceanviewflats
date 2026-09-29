<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Controller\AuthController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private PDO $pdo;
    private InMemoryIpRateLimiter $rateLimiter;
    private AuthService $authService;
    private ViewRenderer $viewRenderer;
    private AuthController $controller;

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
        $auditLogger = new AuditLogger($this->pdo);

        $this->controller = new AuthController(
            auditLogger: $auditLogger,
            authService: $this->authService,
            viewRenderer: $this->viewRenderer
        );
    }

    private function createAdminUser(
        string $email,
        string $password,
        string $name = 'Super Admin',
        string $role = 'admin',
        int $isActive = 1,
        int $failedAttempts = 0,
        ?string $lockedUntil = null
    ): int {
        $hash = $this->authService->hashPassword($password);
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_users (email, password_hash, name, role, is_active, failed_login_attempts, locked_until)
            VALUES (:email, :hash, :name, :role, :active, :failed, :locked)
        ');
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'hash' => $hash,
            'name' => $name,
            'role' => $role,
            'active' => $isActive,
            'failed' => $failedAttempts,
            'locked' => $lockedUntil,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testShowLoginRendersLoginPageWithCsrfToken(): void
    {
        $session = ['csrf_token' => 'sample_csrf_token_value_123'];
        $request = new Request('GET', '/login');

        $response = $this->controller->showLogin($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Ocean View Flats Admin', $body);
        $this->assertStringContainsString('Sign in to access property management', $body);
        $this->assertStringContainsString('sample_csrf_token_value_123', $body);
        $this->assertStringContainsString('action="/login" method="POST"', $body);
    }

    public function testShowLoginRedirectsToDashboardIfAlreadyAuthenticated(): void
    {
        $session = [
            'admin_user_id' => 42,
            'admin_user_name' => 'Alice Admin',
        ];
        $request = new Request('GET', '/login');

        $response = $this->controller->showLogin($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location'] ?? null);
    }

    public function testShowLoginRendersTimeoutAndLogoutReasonNotices(): void
    {
        // 1. Idle timeout notice
        $session = [];
        $requestIdle = new Request('GET', '/login', query: ['reason' => 'idle_timeout']);
        $responseIdle = $this->controller->showLogin($requestIdle, $session);
        $this->assertSame(200, $responseIdle->getStatusCode());
        $this->assertStringContainsString('Your session timed out after 30 minutes of inactivity. Please sign in again.', $responseIdle->getBody());

        // 2. Session expired notice
        $requestExpired = new Request('GET', '/login', query: ['reason' => 'session_expired']);
        $responseExpired = $this->controller->showLogin($requestExpired, $session);
        $this->assertSame(200, $responseExpired->getStatusCode());
        $this->assertStringContainsString('Your session reached its 8-hour maximum lifetime. Please sign in again.', $responseExpired->getBody());

        // 3. Logged out notice
        $requestLoggedOut = new Request('GET', '/login', query: ['reason' => 'logged_out']);
        $responseLoggedOut = $this->controller->showLogin($requestLoggedOut, $session);
        $this->assertSame(200, $responseLoggedOut->getStatusCode());
        $this->assertStringContainsString('You have successfully signed out.', $responseLoggedOut->getBody());
    }

    public function testLoginSuccessEstablishesSessionRotatesCsrfAndAudits(): void
    {
        $userId = $this->createAdminUser(
            email: 'alice@oceanviewflats.com',
            password: 'StrongSecretPassword123!',
            name: 'Alice Operator',
            role: 'manager'
        );

        $session = ['csrf_token' => 'old_csrf_token_abc'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'alice@oceanviewflats.com',
                'password' => 'StrongSecretPassword123!',
            ],
            server: [
                'REMOTE_ADDR' => '192.168.1.100',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Unit-Testing-Agent',
            ]
        );

        $response = $this->controller->login($request, $session);

        // 1. HTTP Redirect to dashboard
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location'] ?? null);

        // 2. Session populated correctly
        $this->assertSame($userId, $session['admin_user_id']);
        $this->assertSame('Alice Operator', $session['admin_user_name']);
        $this->assertSame('alice@oceanviewflats.com', $session['admin_user_email']);
        $this->assertSame('manager', $session['admin_user_role']);

        // 3. CSRF token rotated
        $this->assertNotSame('old_csrf_token_abc', $session['csrf_token']);
        $this->assertSame(64, strlen((string) $session['csrf_token']));

        // 4. Audit log written
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "login_success"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame('admin_user', $log['entity_type']);
        $this->assertSame((string) $userId, $log['entity_id']);
        $this->assertSame($userId, (int) $log['admin_user_id']);
        $this->assertSame('192.168.1.100', $log['ip_address']);
        $this->assertSame('Mozilla/5.0 Unit-Testing-Agent', $log['user_agent']);
        $this->assertStringContainsString('alice@oceanviewflats.com', (string) $log['payload_after']);
    }

    public function testLoginFailureInvalidCredentialsReturns401AndAudits(): void
    {
        $this->createAdminUser('operator@oceanviewflats.com', 'CorrectPass123!');

        $session = ['csrf_token' => 'csrf_token_val'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'operator@oceanviewflats.com',
                'password' => 'IncorrectPassword',
            ],
            server: [
                'REMOTE_ADDR' => '10.10.10.10',
                'HTTP_USER_AGENT' => 'TestBrowser/2.0',
            ]
        );

        $response = $this->controller->login($request, $session);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('The email address or password entered is incorrect.', $response->getBody());
        $this->assertStringContainsString('operator@oceanviewflats.com', $response->getBody());
        $this->assertArrayNotHasKey('admin_user_id', $session);

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "login_failure"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame('auth', $log['entity_type']);
        $this->assertSame('operator@oceanviewflats.com', $log['entity_id']);
        $this->assertNull($log['admin_user_id']);
    }

    public function testLoginFailureAccountLockedReturns401WithLockoutNotice(): void
    {
        $lockedUntil = date('Y-m-d H:i:s', time() + 900); // 15 mins
        $this->createAdminUser(
            email: 'locked@oceanviewflats.com',
            password: 'CorrectPass123!',
            failedAttempts: 5,
            lockedUntil: $lockedUntil
        );

        $session = ['csrf_token' => 'csrf_token_val'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'locked@oceanviewflats.com',
                'password' => 'CorrectPass123!',
            ],
            server: ['REMOTE_ADDR' => '10.10.10.11']
        );

        $response = $this->controller->login($request, $session);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Account temporarily locked. Please try again in 15 minutes.', $response->getBody());
        $this->assertArrayNotHasKey('admin_user_id', $session);

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "login_failure"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertStringContainsString('account_locked', (string) $log['payload_after']);
    }

    public function testLoginFailureAccountDisabledReturns401WithDeactivatedNotice(): void
    {
        $this->createAdminUser(
            email: 'disabled@oceanviewflats.com',
            password: 'CorrectPass123!',
            isActive: 0
        );

        $session = ['csrf_token' => 'csrf_token_val'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'disabled@oceanviewflats.com',
                'password' => 'CorrectPass123!',
            ],
            server: ['REMOTE_ADDR' => '10.10.10.12']
        );

        $response = $this->controller->login($request, $session);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('This account has been deactivated. Please contact an administrator.', $response->getBody());
        $this->assertArrayNotHasKey('admin_user_id', $session);

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "login_failure"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertStringContainsString('account_disabled', (string) $log['payload_after']);
    }

    public function testLoginFailureRateLimitedReturns429WithRetryAfterHeader(): void
    {
        $this->createAdminUser('ratelimited@oceanviewflats.com', 'CorrectPass123!');
        $attackerIp = '198.51.100.99';

        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->recordFailure($attackerIp);
        }

        $session = ['csrf_token' => 'csrf_token_val'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'ratelimited@oceanviewflats.com',
                'password' => 'CorrectPass123!',
            ],
            server: ['REMOTE_ADDR' => $attackerIp]
        );

        $response = $this->controller->login($request, $session);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->getHeaders()['Retry-After'] ?? null);
        $this->assertStringContainsString('Too many failed login attempts from this network. Please retry in 900 seconds.', $response->getBody());
        $this->assertArrayNotHasKey('admin_user_id', $session);

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "login_failure"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertStringContainsString('rate_limited', (string) $log['payload_after']);
    }

    public function testLogoutClearsSessionAndRedirectsWithAuditWhenAuthenticated(): void
    {
        $session = [
            'admin_user_id' => 77,
            'admin_user_name' => 'Operator Seven',
            'admin_user_email' => 'seven@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'active_csrf_token',
        ];

        $request = new Request(
            method: 'GET',
            uri: '/logout',
            server: [
                'REMOTE_ADDR' => '172.16.0.5',
                'HTTP_USER_AGENT' => 'LogoutBrowser/1.0',
            ]
        );

        $response = $this->controller->logout($request, $session);

        // 1. Redirect to login with reason
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?reason=logged_out', $response->getHeaders()['Location'] ?? null);

        // 2. Session cleared
        $this->assertEmpty($session);

        // 3. Audit log recorded
        $stmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = "logout"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame('admin_user', $log['entity_type']);
        $this->assertSame('77', $log['entity_id']);
        $this->assertSame(77, (int) $log['admin_user_id']);
        $this->assertSame('172.16.0.5', $log['ip_address']);
        $this->assertSame('LogoutBrowser/1.0', $log['user_agent']);
    }

    public function testLogoutWhenNotAuthenticatedRedirectsWithoutAudit(): void
    {
        $session = [];
        $request = new Request('GET', '/logout');

        $response = $this->controller->logout($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?reason=logged_out', $response->getHeaders()['Location'] ?? null);
        $this->assertEmpty($session);

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM admin_audit_logs');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testCallableSignatureAdherence(): void
    {
        $session = [];
        $request = new Request('GET', '/login');

        $invoker = function (callable $handler, Request $req, array &$sess): Response {
            return $handler($req, $sess);
        };

        $showResponse = $invoker([$this->controller, 'showLogin'], $request, $session);
        $this->assertInstanceOf(Response::class, $showResponse);

        $logoutResponse = $invoker([$this->controller, 'logout'], $request, $session);
        $this->assertInstanceOf(Response::class, $logoutResponse);
    }

    public function testConstructAcceptsPdoForBackwardsCompatibility(): void
    {
        $controller = new AuthController(
            auditLogger: $this->pdo,
            authService: $this->authService,
            viewRenderer: $this->viewRenderer
        );
        $this->assertInstanceOf(AuthController::class, $controller);
    }
}
