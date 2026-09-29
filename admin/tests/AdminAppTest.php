<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests;

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminAppTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT DEFAULT NULL,
                role TEXT NOT NULL DEFAULT "admin",
                failed_login_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT DEFAULT NULL,
                active INTEGER NOT NULL DEFAULT 1
            );

            CREATE TABLE IF NOT EXISTS admin_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                payload_before TEXT DEFAULT NULL,
                payload_after TEXT DEFAULT NULL,
                admin_user_id INTEGER DEFAULT NULL,
                ip_address TEXT DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS reservations (
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
                mercadopago_preference_id TEXT DEFAULT NULL,
                mercadopago_payment_id TEXT DEFAULT NULL,
                payment_status TEXT DEFAULT NULL,
                payment_method_id TEXT DEFAULT NULL,
                payment_detail TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT "confirmed",
                lang TEXT NOT NULL DEFAULT "en",
                registry_completed INTEGER NOT NULL DEFAULT 0,
                registry_completed_at TEXT DEFAULT NULL,
                door_code TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS guest_registries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL UNIQUE,
                guest_names TEXT DEFAULT NULL,
                document_ids TEXT DEFAULT NULL,
                arrival_time TEXT DEFAULT NULL,
                special_requests TEXT DEFAULT NULL,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS property_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                season_name TEXT NOT NULL,
                price_per_night REAL NOT NULL,
                min_stay INTEGER NOT NULL DEFAULT 2,
                cleaning_fee REAL NOT NULL DEFAULT 0.0,
                resort_fee REAL NOT NULL DEFAULT 0.0,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS calendar_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT NOT NULL,
                channel_source TEXT NOT NULL DEFAULT "direct",
                external_block_id TEXT DEFAULT NULL,
                created_by INTEGER DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            INSERT INTO admin_users (id, name, email) VALUES (1, "Manuel Admin", "admin@oceanviewflats.com");
        ');
    }

    public function testVersionReturnsSemanticVersion(): void
    {
        $this->assertSame('1.0.0', AdminApp::getVersion());
        $this->assertSame('1.0.0', AdminApp::VERSION);
    }

    public function testKernelConstructionWithDefaultDependencies(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $this->assertInstanceOf(AdminApp::class, $app);

        $session = [];
        $request = new Request('GET', '/login');
        $response = $app->handle($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sign in', $response->getBody());
    }

    public function testKernelConstructionWithOptionsOverridesAppliedViaBehavior(): void
    {
        // 1. Custom rate limiter override: pre-exhaust attempts for client IP
        $rateLimiter = new InMemoryIpRateLimiter(maxAttempts: 1);
        $rateLimiter->recordFailure('127.0.0.1');

        // 2. Custom audit logger override with dedicated separate PDO database
        $auditPdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $auditPdo->exec('
            CREATE TABLE admin_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                payload_before TEXT DEFAULT NULL,
                payload_after TEXT DEFAULT NULL,
                admin_user_id INTEGER DEFAULT NULL,
                ip_address TEXT DEFAULT NULL,
                user_agent TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');
        $auditLogger = new AuditLogger($auditPdo);

        // 3. Custom CSV pricing path override
        $tempCsv = tempnam(sys_get_temp_dir(), 'rates_') . '.csv';
        file_put_contents(
            $tempCsv,
            "property_id,start_date,end_date,nightly_rate_cop,minimum_stay\n1707,2026-12-01,2026-12-10,888777,2\n"
        );

        $customDomain = 'https://custom-admin-test.example.com';

        $app = AdminApp::createDefault($this->pdo, [
            'rate_limiter' => $rateLimiter,
            'audit_logger' => $auditLogger,
            'csv_path' => $tempCsv,
            'public_site_url' => $customDomain,
        ]);

        $this->assertInstanceOf(AdminApp::class, $app);

        // Verify Rate Limiter override behavior: POST /login is rejected with rate-limited status
        $session = ['csrf_token' => 'test-csrf-token'];
        $loginRequest = new Request(
            method: 'POST',
            uri: '/login',
            post: [
                'email' => 'admin@oceanviewflats.com',
                'password' => 'WrongPassword!',
                'csrf_token' => 'test-csrf-token',
            ],
            server: ['REMOTE_ADDR' => '127.0.0.1']
        );
        $loginResponse = $app->handle($loginRequest, $session);
        $this->assertSame(429, $loginResponse->getStatusCode());
        $this->assertStringContainsString('Too many failed login attempts', $loginResponse->getBody());

        // Verify Audit Logger override behavior: failure was logged to $auditPdo
        $stmt = $auditPdo->query("SELECT COUNT(*) FROM admin_audit_logs WHERE action = 'login_failure'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $auditCount = (int) $stmt->fetchColumn();
        $this->assertSame(1, $auditCount);

        // Verify CSV path propagation override behavior: quote calculation uses custom CSV rate ($888,777 * 2 nights = $1,777,554)
        $quoteSession = [
            'admin_user_id' => 1,
            'csrf_token' => 'quote-token',
        ];
        $quoteRequest = new Request(
            method: 'POST',
            uri: '/reservations/quote-preview',
            post: [
                'property_id' => '1707',
                'check_in' => '2026-12-01',
                'check_out' => '2026-12-03',
                'source' => 'manual_override',
                'csrf_token' => 'quote-token',
            ]
        );
        $quoteResponse = $app->handle($quoteRequest, $quoteSession);
        $this->assertSame(200, $quoteResponse->getStatusCode());
        $this->assertStringContainsString('1,777,554', $quoteResponse->getBody());
        $this->assertStringContainsString('1897554', $quoteResponse->getBody());

        // Verify public site URL override behavior: registry link contains custom site domain
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES ('res_custom_url_test', '1707', 'Guest', 'guest@example.com', '123', '2026-10-01', '2026-10-05', 1000, 'confirmed');
        ");
        $registrySession = [
            'admin_user_id' => 1,
            'csrf_token' => 'reg-token',
        ];
        $registryRequest = new Request('GET', '/reservations/res_custom_url_test/registry');
        $registryResponse = $app->handle($registryRequest, $registrySession);
        $this->assertSame(200, $registryResponse->getStatusCode());
        $this->assertStringContainsString($customDomain, $registryResponse->getBody());

        @unlink($tempCsv);
    }

    public function testAllTwentySevenRoutesAreRegistered(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $expectedRoutes = [
            // Static routes (15)
            ['GET', '/login'],
            ['POST', '/login'],
            ['GET', '/logout'],
            ['GET', '/'],
            ['GET', '/reservations'],
            ['GET', '/reservations/new'],
            ['POST', '/reservations/quote-preview'],
            ['POST', '/reservations/create-manual'],
            ['GET', '/rates'],
            ['GET', '/rates/new'],
            ['POST', '/rates'],
            ['POST', '/rates/seed-from-csv'],
            ['GET', '/calendar-blocks'],
            ['GET', '/calendar-blocks/new'],
            ['POST', '/calendar-blocks'],

            // Dynamic routes (12)
            ['GET', '/reservations/res_test_uid'],
            ['GET', '/reservations/res_test_uid/registry'],
            ['POST', '/reservations/res_test_uid/registry/complete'],
            ['POST', '/reservations/res_test_uid/door-code/override'],
            ['POST', '/reservations/res_test_uid/door-code/regenerate'],
            ['GET', '/reservations/res_test_uid/cancel-modal'],
            ['POST', '/reservations/res_test_uid/cancel'],
            ['GET', '/rates/42/edit'],
            ['POST', '/rates/42'],
            ['DELETE', '/rates/42'],
            ['DELETE', '/calendar-blocks/42'],
            ['POST', '/calendar-blocks/42/delete'],
        ];

        $this->assertCount(27, $expectedRoutes);

        foreach ($expectedRoutes as [$method, $path]) {
            $session = ['admin_user_id' => 1];
            // Non-mutating OPTIONS request to inspect router registration and advertised Allow headers
            $request = new Request('OPTIONS', $path);
            $response = $app->handle($request, $session);

            $this->assertSame(405, $response->getStatusCode(), "Route {$method} {$path} returned {$response->getStatusCode()} instead of 405");
            $allow = $response->getHeaders()['Allow'] ?? '';
            $this->assertStringContainsString($method, $allow, "Route {$method} {$path} does not advertise {$method} in Allow header: '{$allow}'");
        }

        // Verify unregistered routes return 404
        $session = ['admin_user_id' => 1];
        $unknownRouteResponse = $app->handle(new Request('OPTIONS', '/completely-unknown-route'), $session);
        $this->assertSame(404, $unknownRouteResponse->getStatusCode());

        $unknownSubpathResponse = $app->handle(new Request('OPTIONS', '/reservations/res_test_uid/unknown-subpath'), $session);
        $this->assertSame(404, $unknownSubpathResponse->getStatusCode());
    }

    public function testHandleRedirectsUnauthenticatedRequestsOnProtectedRoutesToLogin(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $session = [];
        $request = new Request('GET', '/');
        $response = $app->handle($request, $session);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location'] ?? null);

        $requestReservations = new Request('GET', '/reservations');
        $responseReservations = $app->handle($requestReservations, $session);

        $this->assertSame(302, $responseReservations->getStatusCode());
        $this->assertSame('/login', $responseReservations->getHeaders()['Location'] ?? null);
    }

    public function testHandleCorrectlyDispatchesAuthenticatedRequestsToDashboard(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_email' => 'admin@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'dummy-token',
        ];
        $request = new Request('GET', '/');
        $response = $app->handle($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard', $response->getBody());
        $this->assertStringContainsString('Manuel Admin', $response->getBody());
    }

    public function testHandleDispatchesPublicWhitelistedRoutesWithoutAuthentication(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $session = [];
        $request = new Request('GET', '/login');
        $response = $app->handle($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sign in', $response->getBody());
    }

    public function testHandleReturns404ForUnknownRoutes(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/unknown-route-that-does-not-exist');
        $response = $app->handle($request, $session);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', $response->getBody());
    }

    public function testHandleReturns405WithAllowHeaderForMethodMismatch(): void
    {
        $app = AdminApp::createDefault($this->pdo);

        $session = ['admin_user_id' => 1];
        // GET on POST-only route /rates/seed-from-csv (GET is non-mutating so CSRF is not required)
        $request = new Request('GET', '/rates/seed-from-csv');
        $response = $app->handle($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('DELETE, POST', $response->getHeaders()['Allow'] ?? null);
        $this->assertStringContainsString('405 Method Not Allowed', $response->getBody());

        // Mutating method POST on GET-only route / with valid CSRF token
        $csrfToken = 'test-valid-csrf-token';
        $session = [
            'admin_user_id' => 1,
            'csrf_token' => $csrfToken,
        ];
        $request = new Request('POST', '/', [], ['csrf_token' => $csrfToken]);
        $response = $app->handle($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET', $response->getHeaders()['Allow'] ?? null);
        $this->assertStringContainsString('405 Method Not Allowed', $response->getBody());
    }
}
