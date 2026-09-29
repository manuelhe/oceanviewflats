<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests;

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Service\InMemoryMercadoPagoRefundClient;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\InMemoryEmailSender;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use OceanViewFlats\Domain\Quote\QuoteEngine;
use OceanViewFlats\Domain\Reservation\ReservationLedger;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class AdminAppTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
        $this->assertSame($this->pdo, $app->getPdo());
        $this->assertInstanceOf(Router::class, $app->getRouter());
    }

    public function testKernelConstructionWithOptionsOverrides(): void
    {
        $rateLimiter = new InMemoryIpRateLimiter();
        $authService = new AuthService($this->pdo, $rateLimiter);
        $emailSender = new InMemoryEmailSender();
        $refundClient = new InMemoryMercadoPagoRefundClient();
        $customRouter = new Router();
        $viewsPath = dirname(__DIR__) . '/src/Views';
        $viewRenderer = new ViewRenderer($viewsPath);
        $auditLogger = new AuditLogger($this->pdo);
        $ratesConfig = PropertyRatesConfig::createDefault();
        $ledger = ReservationLedger::createDefault($this->pdo);
        $emailRenderer = new ConfirmationEmailRenderer('https://test.oceanviewflats.com');
        $cancellationEmailRenderer = new CancellationEmailRenderer('https://test.oceanviewflats.com');
        $sessionMiddleware = new SessionMiddleware();
        $csrfMiddleware = new CsrfMiddleware();
        $authMiddleware = new AuthMiddleware();

        $app = AdminApp::createDefault($this->pdo, [
            'rate_limiter' => $rateLimiter,
            'auth_service' => $authService,
            'email_sender' => $emailSender,
            'refund_client' => $refundClient,
            'router' => $customRouter,
            'views_path' => $viewsPath,
            'view_renderer' => $viewRenderer,
            'audit_logger' => $auditLogger,
            'rates_config' => $ratesConfig,
            'ledger' => $ledger,
            'email_renderer' => $emailRenderer,
            'cancellation_email_renderer' => $cancellationEmailRenderer,
            'public_site_url' => 'https://test.oceanviewflats.com',
            'mp_access_token' => 'TEST_TOKEN_APP',
            'csv_path' => 'custom/prices.csv',
            'session_middleware' => $sessionMiddleware,
            'csrf_middleware' => $csrfMiddleware,
            'auth_middleware' => $authMiddleware,
        ]);

        $this->assertInstanceOf(AdminApp::class, $app);
        $this->assertSame($this->pdo, $app->getPdo());
        $this->assertSame($customRouter, $app->getRouter());
    }

    public function testAllTwentySevenRoutesAreRegistered(): void
    {
        $app = AdminApp::createDefault($this->pdo);
        $router = $app->getRouter();

        $staticRef = new ReflectionProperty($router, 'staticRoutes');
        $dynamicRef = new ReflectionProperty($router, 'dynamicRoutes');

        /** @var array<string, array<string, callable>> $staticRoutes */
        $staticRoutes = $staticRef->getValue($router);
        /** @var array<string, list<array{pattern: string, handler: callable}>> $dynamicRoutes */
        $dynamicRoutes = $dynamicRef->getValue($router);

        $staticCount = array_sum(array_map('count', $staticRoutes));
        $dynamicCount = array_sum(array_map('count', $dynamicRoutes));

        $this->assertSame(15, $staticCount, 'Expected 15 static routes');
        $this->assertSame(12, $dynamicCount, 'Expected 12 dynamic routes');
        $this->assertSame(27, $staticCount + $dynamicCount, 'Expected total of 27 registered routes');

        $expectedStatic = [
            'GET' => [
                '/login',
                '/logout',
                '/',
                '/reservations',
                '/reservations/new',
                '/rates',
                '/rates/new',
                '/calendar-blocks',
                '/calendar-blocks/new',
            ],
            'POST' => [
                '/login',
                '/reservations/quote-preview',
                '/reservations/create-manual',
                '/rates',
                '/rates/seed-from-csv',
                '/calendar-blocks',
            ],
        ];

        foreach ($expectedStatic as $method => $paths) {
            foreach ($paths as $path) {
                $this->assertArrayHasKey($path, $staticRoutes[$method] ?? [], "Missing static route {$method} {$path}");
            }
        }
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
