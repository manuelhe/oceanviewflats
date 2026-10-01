<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminFrontControllerTest extends TestCase
{
    private PDO $pdo;
    private InMemoryIpRateLimiter $rateLimiter;
    private AuthService $authService;
    private AdminApp $app;

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

        $this->app = AdminApp::createDefault($this->pdo, ['rate_limiter' => $this->rateLimiter]);
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

        $response = $this->app->handle($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($session['csrf_token']);
        $this->assertStringContainsString('Ocean View Flats Admin', $response->getBody());
        $this->assertStringContainsString((string) $session['csrf_token'], $response->getBody());
    }

    public function testGetLoginRedirectsToDashboardIfAlreadyAuthenticated(): void
    {
        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/login');

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/', $response->getHeaders()['Location']);

        $this->assertSame($userId, $session['admin_user_id']);
        $this->assertSame('Alice Manager', $session['admin_user_name']);
        $this->assertSame('manager@oceanviewflats.com', $session['admin_user_email']);
        $this->assertSame('admin', $session['admin_user_role']);
        $this->assertNotSame('valid_csrf_token', $session['csrf_token']);
        $this->assertSame(64, strlen((string) $session['csrf_token']));

        // Verify audit log
        $stmt = $this->pdo->prepare('SELECT action, entity_type, entity_id, admin_user_id FROM admin_audit_logs WHERE action = "login_success"');
        $stmt->execute();
        $log = $stmt->fetch();
        $this->assertIsArray($log);
        $this->assertSame((string) $userId, $log['entity_id']);
        $this->assertSame($userId, (int) $log['admin_user_id']);
    }

    public function testPostLoginRateLimitedReturns429WithRetryAfterHeader(): void
    {
        $this->createAdminUser('rate_limited@oceanviewflats.com', 'ValidPass123!');
        $session = ['csrf_token' => 'valid_csrf_token'];

        // Exhaust IP attempts (10 max)
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->recordFailure('198.51.100.5');
        }

        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'csrf_token' => 'valid_csrf_token',
                'email' => 'rate_limited@oceanviewflats.com',
                'password' => 'ValidPass123!',
            ],
            server: ['REMOTE_ADDR' => '198.51.100.5']
        );

        $response = $this->app->handle($request, $session);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('900', $response->getHeaders()['Retry-After'] ?? null);
        $this->assertStringContainsString('Too many failed login attempts', $response->getBody());
    }

    public function testProtectedDashboardGatedByAuthMiddleware(): void
    {
        $session = []; // Unauthenticated
        $request = new Request('GET', '/');

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

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

        $response = $this->app->handle($request, $session);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', $response->getBody());
    }

    public function testMethodNotAllowedReturns405WithAllowHeader(): void
    {
        $session = [
            'admin_user_id' => 1,
            'csrf_token' => 'valid_csrf_token',
        ];
        $request = new Request(
            method: 'DELETE',
            uri: '/login',
            post: ['csrf_token' => 'valid_csrf_token']
        );

        $response = $this->app->handle($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaders()['Allow']);
        $this->assertStringContainsString('405 Method Not Allowed', $response->getBody());
    }

    public function testEndToEndLoginFlowWithCsrfPersistenceOnLocalhost(): void
    {
        $userId = $this->createAdminUser('local_admin@oceanviewflats.com', 'LocalPass123!', 'Local Admin');

        // Step 1: GET /login on localhost
        $session = [];
        $getRequest = new Request(
            method: 'GET',
            uri: '/login',
            server: [
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
            ]
        );

        $getResponse = $this->app->handle($getRequest, $session);

        $this->assertSame(200, $getResponse->getStatusCode());
        $this->assertNotEmpty($session['csrf_token']);
        $tokenOnPage = (string) $session['csrf_token'];

        // Verify cookie params on localhost are RFC 6265 compliant Host-Only cookies
        $cookieParams = \OceanViewFlats\Admin\Middleware\SessionMiddleware::resolveCookieParams('localhost', false);
        $this->assertSame('', $cookieParams['domain'], 'Localhost must use Host-Only cookies (empty domain)');
        $this->assertFalse($cookieParams['secure']);

        // Step 2: POST /login with retained session and CSRF token
        $postRequest = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'local_admin@oceanviewflats.com',
                'password' => 'LocalPass123!',
                'csrf_token' => $tokenOnPage,
            ],
            server: [
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Localhost Test',
            ]
        );

        $postResponse = $this->app->handle($postRequest, $session);

        // Crucial assertion: Must NOT be 403 Forbidden: Invalid CSRF Token
        $this->assertSame(302, $postResponse->getStatusCode());
        $this->assertSame('/', $postResponse->getHeaders()['Location']);
        $this->assertSame($userId, $session['admin_user_id']);
        $this->assertNotSame($tokenOnPage, $session['csrf_token'], 'CSRF token should rotate on login');

        // Step 3: GET / with authenticated session
        $dashboardRequest = new Request(
            method: 'GET',
            uri: '/',
            server: [
                'HTTP_HOST' => 'localhost:8080',
                'SERVER_NAME' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1',
            ]
        );

        $dashboardResponse = $this->app->handle($dashboardRequest, $session);
        $this->assertSame(200, $dashboardResponse->getStatusCode());
        $this->assertStringContainsString('Local Admin', $dashboardResponse->getBody());
        $this->assertStringContainsString((string) $session['csrf_token'], $dashboardResponse->getBody());
    }

    public function testEndToEndLoginFlowWithCsrfPersistenceBehindReverseProxy(): void
    {
        $userId = $this->createAdminUser('proxy_admin@oceanviewflats.com', 'ProxyPass123!', 'Proxy Admin');

        // Step 1: GET /login behind reverse proxy terminating SSL
        $session = [];
        $serverEnv = [
            'HTTP_HOST' => 'admin.oceanviewflats.com',
            'SERVER_NAME' => 'admin.oceanviewflats.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '10.0.1.20',
        ];

        $getRequest = new Request(
            method: 'GET',
            uri: '/login',
            server: $serverEnv
        );

        $getResponse = $this->app->handle($getRequest, $session);
        $this->assertSame(200, $getResponse->getStatusCode());
        $csrfToken = (string) $session['csrf_token'];

        // Step 2: POST /login with proxy HTTPS headers
        $postRequest = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'proxy_admin@oceanviewflats.com',
                'password' => 'ProxyPass123!',
                'csrf_token' => $csrfToken,
            ],
            server: array_merge($serverEnv, ['HTTP_USER_AGENT' => 'Proxy Client'])
        );

        $postResponse = $this->app->handle($postRequest, $session);

        $this->assertSame(302, $postResponse->getStatusCode());
        $this->assertSame('/', $postResponse->getHeaders()['Location']);
        $this->assertSame($userId, $session['admin_user_id']);
    }

    public function testEndToEndLoginFlowWithCsrfPersistenceOnIpAddress(): void
    {
        $userId = $this->createAdminUser('ip_admin@oceanviewflats.com', 'IpPass123!', 'IP Admin');

        // Step 1: GET /login on raw IPv4 address
        $session = [];
        $serverEnv = [
            'HTTP_HOST' => '192.168.1.150:8000',
            'SERVER_NAME' => '192.168.1.150',
            'SERVER_PORT' => '8000',
            'REMOTE_ADDR' => '192.168.1.50',
        ];

        $getRequest = new Request(
            method: 'GET',
            uri: '/login',
            server: $serverEnv
        );

        $getResponse = $this->app->handle($getRequest, $session);
        $this->assertSame(200, $getResponse->getStatusCode());
        $csrfToken = (string) $session['csrf_token'];

        // Verify cookie params on IP address resolve to empty domain (Host-Only)
        $cookieParams = \OceanViewFlats\Admin\Middleware\SessionMiddleware::resolveCookieParams('192.168.1.150:8000', false);
        $this->assertSame('', $cookieParams['domain'], 'IP address must use empty domain per RFC 6265');
        $this->assertFalse($cookieParams['secure']);

        // Step 2: POST /login with retained session and CSRF token
        $postRequest = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'ip_admin@oceanviewflats.com',
                'password' => 'IpPass123!',
                'csrf_token' => $csrfToken,
            ],
            server: array_merge($serverEnv, ['HTTP_USER_AGENT' => 'IP Browser Test'])
        );

        $postResponse = $this->app->handle($postRequest, $session);

        $this->assertSame(302, $postResponse->getStatusCode());
        $this->assertSame('/', $postResponse->getHeaders()['Location']);
        $this->assertSame($userId, $session['admin_user_id']);
    }
}

