<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Controller\RateController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Repository\AdminRateRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Quote\CsvRateSource;
use OceanViewFlats\Domain\Quote\PdoRateSource;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminRateRoutesTest extends TestCase
{
    private PDO $pdo;
    private Router $router;
    private AdminRateRepository $rateRepo;
    private string $tempCsvPath;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE
            );

            CREATE TABLE property_rates (
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

            INSERT INTO admin_users (id, name, email) VALUES (1, "Manuel Admin", "admin@oceanviewflats.com");
        ');

        $this->tempCsvPath = sys_get_temp_dir() . '/test_prices_' . uniqid() . '.csv';
        file_put_contents($this->tempCsvPath, implode("\n", [
            'property_id,start_date,end_date,nightly_rate_cop,min_stay',
            '1606,2026-01-01,2026-06-30,350000,2',
            '1707,2026-01-01,2026-12-31,450000,2',
        ]));

        $ratesConfig = PropertyRatesConfig::createDefault();
        $this->rateRepo = new AdminRateRepository($this->pdo, $ratesConfig);
        $rateSource = new PdoRateSource($this->pdo, new CsvRateSource());
        $viewRenderer = new ViewRenderer(dirname(__DIR__, 2) . '/src/Views');
        $auditLogger = new AuditLogger($this->pdo);

        $rateController = new RateController(
            rateRepository: $this->rateRepo,
            rateSource: $rateSource,
            viewRenderer: $viewRenderer,
            auditLogger: $auditLogger,
            ratesConfig: $ratesConfig,
            csvPath: $this->tempCsvPath
        );

        $sessionMiddleware = new SessionMiddleware();
        $csrfMiddleware = new CsrfMiddleware();
        $authMiddleware = new AuthMiddleware();

        $this->router = new Router(
            sessionMiddleware: $sessionMiddleware,
            csrfMiddleware: $csrfMiddleware,
            authMiddleware: $authMiddleware
        );

        $this->router->get('/rates', [$rateController, 'index'])
            ->get('/rates/new', [$rateController, 'newTier'])
            ->post('/rates', [$rateController, 'create'])
            ->get('/rates/{id}/edit', [$rateController, 'edit'])
            ->post('/rates/{id}', [$rateController, 'update'])
            ->delete('/rates/{id}', [$rateController, 'delete'])
            ->post('/rates/seed-from-csv', [$rateController, 'seedFromCsv']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempCsvPath)) {
            unlink($this->tempCsvPath);
        }
    }

    public function testUnauthenticatedRequestToRatesRedirectsToLogin(): void
    {
        $request = new Request('GET', '/rates');
        $session = [];

        $response = $this->router->dispatch($request, $session);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeader('Location'));
    }

    public function testAuthenticatedGetRatesReturns200(): void
    {
        $request = new Request('GET', '/rates', query: ['property_id' => '1606', 'year' => '2026']);
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_email' => 'admin@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'valid-csrf-token',
        ];

        $response = $this->router->dispatch($request, $session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Seasonal Pricing & Rates', $response->getBody());
    }

    public function testPostRatesFailsWithoutCsrf(): void
    {
        $request = new Request('POST', '/rates', post: [
            'property_id' => '1606',
            'season_name' => 'Test Season',
        ]);
        $session = [
            'admin_user_id' => 1,
            'csrf_token' => 'valid-csrf-token',
        ];

        $response = $this->router->dispatch($request, $session);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('CSRF', $response->getBody());
    }

    public function testFullTierLifecycleThroughRouter(): void
    {
        $csrf = 'valid-csrf-token';
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_email' => 'admin@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => $csrf,
        ];

        // 1. GET /rates/new
        $reqNew = new Request('GET', '/rates/new', query: ['property_id' => '1606', 'year' => '2026']);
        $resNew = $this->router->dispatch($reqNew, $session);
        $this->assertSame(200, $resNew->getStatusCode());
        $this->assertStringContainsString('Create Seasonal Rate Tier', $resNew->getBody());

        // 2. POST /rates (Create)
        $reqCreate = new Request('POST', '/rates', post: [
            'csrf_token' => $csrf,
            'property_id' => '1606',
            'season_name' => 'Mid-Year Vacation',
            'start_date' => '2026-06-15',
            'end_date' => '2026-07-15',
            'price_per_night' => '420000',
            'min_stay' => '3',
            'year' => '2026',
        ]);
        $resCreate = $this->router->dispatch($reqCreate, $session);
        $this->assertSame(200, $resCreate->getStatusCode());
        $this->assertSame('rateUpdated', $resCreate->getHeader('HX-Trigger'));

        $rates = $this->rateRepo->getRatesForProperty('1606', 2026);
        $this->assertCount(1, $rates);
        $tierId = (int) $rates[0]['id'];

        // 3. GET /rates/{id}/edit
        $reqEdit = new Request('GET', "/rates/{$tierId}/edit");
        $resEdit = $this->router->dispatch($reqEdit, $session);
        $this->assertSame(200, $resEdit->getStatusCode());
        $this->assertStringContainsString('Edit Seasonal Rate Tier', $resEdit->getBody());
        $this->assertStringContainsString('Mid-Year Vacation', $resEdit->getBody());

        // 4. POST /rates/{id} (Update)
        $reqUpdate = new Request('POST', "/rates/{$tierId}", post: [
            'csrf_token' => $csrf,
            'season_name' => 'Mid-Year Vacation Renamed',
            'start_date' => '2026-06-15',
            'end_date' => '2026-07-20',
            'price_per_night' => '460000',
            'min_stay' => '4',
            'year' => '2026',
        ]);
        $resUpdate = $this->router->dispatch($reqUpdate, $session);
        $this->assertSame(200, $resUpdate->getStatusCode());
        $this->assertSame('rateUpdated', $resUpdate->getHeader('HX-Trigger'));

        $updatedTier = $this->rateRepo->findRateById($tierId);
        $this->assertNotNull($updatedTier);
        $this->assertSame('Mid-Year Vacation Renamed', $updatedTier['season_name']);

        // 5. DELETE /rates/{id} with CSRF header
        $reqDelete = new Request(
            'DELETE',
            "/rates/{$tierId}",
            server: ['HTTP_X_CSRF_TOKEN' => $csrf]
        );
        $resDelete = $this->router->dispatch($reqDelete, $session);
        $this->assertSame(200, $resDelete->getStatusCode());
        $this->assertSame('rateUpdated', $resDelete->getHeader('HX-Trigger'));

        $this->assertNull($this->rateRepo->findRateById($tierId));
    }

    public function testSeedFromCsvThroughRouter(): void
    {
        $csrf = 'valid-csrf-token';
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_email' => 'admin@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => $csrf,
        ];

        $reqSeed = new Request('POST', '/rates/seed-from-csv', post: [
            'csrf_token' => $csrf,
            'property_id' => '1606',
            'year' => '2026',
        ]);

        $resSeed = $this->router->dispatch($reqSeed, $session);
        $this->assertSame(200, $resSeed->getStatusCode());
        $this->assertSame('rateUpdated', $resSeed->getHeader('HX-Trigger'));
        $this->assertStringContainsString('Successfully imported 2 seasonal rate tiers', $resSeed->getBody());
    }
}
