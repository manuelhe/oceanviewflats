<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Service\InMemoryMercadoPagoRefundClient;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\InMemoryEmailSender;
use OceanViewFlats\Domain\Quote\QuoteEngine;
use OceanViewFlats\Domain\Reservation\ReservationLedger;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AdminReservationRoutesTest extends TestCase
{
    private PDO $pdo;
    private Router $router;
    private InMemoryMercadoPagoRefundClient $refundClient;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->initDatabaseSchema();
        $this->configureRouter();
    }

    private function initDatabaseSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE reservations (
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
                status TEXT NOT NULL DEFAULT "pending_payment",
                lang TEXT NOT NULL DEFAULT "en",
                registry_completed INTEGER NOT NULL DEFAULT 0,
                registry_completed_at TEXT DEFAULT NULL,
                door_code TEXT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE guest_registries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL,
                property_id TEXT NOT NULL,
                check_in TEXT NOT NULL,
                check_out TEXT NOT NULL,
                guest_count INTEGER NOT NULL DEFAULT 1,
                guests_payload TEXT NOT NULL,
                car_plates TEXT DEFAULT NULL,
                car_model TEXT DEFAULT NULL,
                ip_address TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

            CREATE TABLE reservation_refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT NOT NULL,
                mercadopago_refund_id TEXT DEFAULT NULL UNIQUE,
                mercadopago_payment_id TEXT NOT NULL,
                amount NUMERIC NOT NULL,
                status TEXT NOT NULL DEFAULT "approved",
                reason TEXT DEFAULT NULL,
                source TEXT NOT NULL DEFAULT "admin",
                admin_user_id INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');
    }

    private function configureRouter(): void
    {
        $viewsPath = dirname(__DIR__, 2) . '/src/Views';
        $viewRenderer = new ViewRenderer($viewsPath);
        $reservationRepo = new AdminReservationRepository($this->pdo);
        $auditLogger = new AuditLogger($this->pdo);
        $ledger = ReservationLedger::createDefault($this->pdo);
        $quoteEngine = QuoteEngine::createDefault();
        $emailRenderer = new ConfirmationEmailRenderer('https://oceanviewflats.com');
        $emailSender = new InMemoryEmailSender();

        $this->refundClient = new InMemoryMercadoPagoRefundClient();

        $reservationController = new ReservationController(
            repository: $reservationRepo,
            viewRenderer: $viewRenderer,
            auditLogger: $auditLogger,
            ledger: $ledger,
            quoteEngine: $quoteEngine,
            emailRenderer: $emailRenderer,
            emailSender: $emailSender,
            publicSiteUrl: 'https://oceanviewflats.com',
            refundClient: $this->refundClient
        );

        $this->router = new Router(
            sessionMiddleware: new SessionMiddleware(),
            csrfMiddleware: new CsrfMiddleware(),
            authMiddleware: new AuthMiddleware()
        );

        $this->router->get('/reservations', [$reservationController, 'list'])
            ->get('/reservations/new', [$reservationController, 'newReservation'])
            ->post('/reservations/quote-preview', [$reservationController, 'quotePreview'])
            ->post('/reservations/create-manual', [$reservationController, 'createManual'])
            ->get('/reservations/{uid}', [$reservationController, 'show'])
            ->get('/reservations/{uid}/registry', [$reservationController, 'showRegistry'])
            ->post('/reservations/{uid}/registry/complete', [$reservationController, 'completeRegistry'])
            ->post('/reservations/{uid}/door-code/override', [$reservationController, 'overrideDoorCode'])
            ->post('/reservations/{uid}/door-code/regenerate', [$reservationController, 'regenerateDoorCode'])
            ->get('/reservations/{uid}/cancel-modal', [$reservationController, 'cancelModal'])
            ->post('/reservations/{uid}/cancel', [$reservationController, 'cancel']);
    }

    private function dispatchAdmin(Request $request): Response
    {
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Operator Manuel',
            'admin_user_email' => 'operator@test.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'test-csrf-token',
        ];
        return $this->router->dispatch($request, $session);
    }


    public function testReservationsRendersForAuthenticatedUser(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES ('res-int-1', '1606', 'Maria Test', 'maria@test.com', '+573001234567', '2026-11-01', '2026-11-05', 1000000.00, 'confirmed');
        ");

        $response = $this->dispatchAdmin(new Request('GET', '/reservations'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Reservations Management', $response->getBody());
        $this->assertStringContainsString('Maria Test', $response->getBody());
    }

    public function testReservationShowRouteResolvesUidAttribute(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES ('res-int-2', '1707', 'Pedro Test', 'pedro@test.com', '+573007654321', '2026-11-10', '2026-11-15', 1500000.00, 'confirmed');
        ");

        $response = $this->dispatchAdmin(new Request('GET', '/reservations/res-int-2', server: ['HTTP_HX_REQUEST' => 'true']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Pedro Test', $response->getBody());
        $this->assertStringContainsString('res-int-2', $response->getBody());
    }

    public function testReservationRegistryRouteResolvesUidAttribute(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, registry_completed)
            VALUES ('res-int-3', '1606', 'Sofia Test', 'sofia@test.com', '+573009998888', '2026-12-01', '2026-12-05', 2000000.00, 'confirmed', 1);

            INSERT INTO guest_registries (reservation_uid, property_id, check_in, check_out, guest_count, guests_payload)
            VALUES ('res-int-3', '1606', '2026-12-01', '2026-12-05', 1, '[{\"full_name\":\"Sofia Test\",\"doc_type\":\"CC\",\"doc_number\":\"99999999\"}]');
        ");

        $response = $this->dispatchAdmin(new Request('GET', '/reservations/res-int-3/registry', server: ['HTTP_HX_REQUEST' => 'true']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Guest Registry Dossier', $response->getBody());
        $this->assertStringContainsString('Sofia Test', $response->getBody());
    }

    public function testNewReservationRouteResolvesAndRendersModal(): void
    {
        $response = $this->dispatchAdmin(new Request('GET', '/reservations/new', server: ['HTTP_HX_REQUEST' => 'true']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Create Manual Reservation', $response->getBody());
    }

    public function testQuotePreviewRouteComputesLivePricing(): void
    {
        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/quote-preview',
            post: [
                'property_id' => '1606',
                'check_in' => '2026-11-20',
                'check_out' => '2026-11-23',
                'csrf_token' => 'test-csrf-token',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Available (3 Nights)', $response->getBody());
    }

    public function testCreateManualReservationRouteStoresBookingAndEmitsLocationHeader(): void
    {
        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/create-manual',
            post: [
                'property_id' => '1606',
                'check_in' => '2026-12-10',
                'check_out' => '2026-12-15',
                'source' => 'cash',
                'total_price' => '1500000',
                'guest_name' => 'Route Tester',
                'guest_email' => 'route@test.com',
                'guest_phone' => '+573001112233',
                'notes' => 'Route integration test',
                'csrf_token' => 'test-csrf-token',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('HX-Location', $headers);
        $this->assertMatchesRegularExpression('#^/reservations/res-man-[a-f0-9]+$#', $headers['HX-Location']);

        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE guest_email = :email');
        $stmt->execute(['email' => 'route@test.com']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('Route Tester', $row['guest_name']);
    }

    public function testOverrideDoorCodeRouteUpdatesDoorCode(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, door_code)
            VALUES ('res-int-override', '1606', 'Code Tester', 'code@test.com', '+573001112233', '2026-11-20', '2026-11-25', 1000000.00, 'confirmed', '1111#');
        ");

        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/res-int-override/door-code/override',
            post: [
                'door_code' => '998877#',
                'csrf_token' => 'test-csrf-token',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        $stmt = $this->pdo->query("SELECT door_code FROM reservations WHERE reservation_uid = 'res-int-override'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertSame('998877#', $stmt->fetchColumn());
    }

    public function testRegenerateDoorCodeRouteUpdatesDoorCode(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, door_code)
            VALUES ('res-int-regen', '1707', 'Regen Tester', 'regen@test.com', '+573001112233', '2026-11-25', '2026-11-30', 1200000.00, 'confirmed', '2222#');
        ");

        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/res-int-regen/door-code/regenerate',
            post: [
                'csrf_token' => 'test-csrf-token',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        $stmt = $this->pdo->query("SELECT door_code FROM reservations WHERE reservation_uid = 'res-int-regen'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $doorCode = (string) $stmt->fetchColumn();
        $this->assertNotSame('2222#', $doorCode);
        $this->assertMatchesRegularExpression('/^0[0-9]{6}#$/', $doorCode);
    }

    public function testCompleteRegistryRouteMarksCompletedAndEmitsHxTrigger(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, registry_completed, door_code)
            VALUES ('res-int-comp', '1606', 'Comp Tester', 'comp@test.com', '+573001112233', '2026-12-05', '2026-12-10', 1000000.00, 'confirmed', 0, NULL);
        ");

        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/res-int-comp/registry/complete',
            post: [
                'csrf_token' => 'test-csrf-token',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        $stmt = $this->pdo->query("SELECT registry_completed, door_code FROM reservations WHERE reservation_uid = 'res-int-comp'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['registry_completed']);
        $this->assertMatchesRegularExpression('/^0[0-9]{6}#$/', (string) $row['door_code']);
    }

    public function testCancelModalRouteReturnsModalContent(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES ('res-int-cancel-modal', '1606', 'Modal Tester', 'modal@test.com', '+573001112233', '2026-12-15', '2026-12-20', 800000.00, 'confirmed');
        ");

        $response = $this->dispatchAdmin(new Request(
            'GET',
            '/reservations/res-int-cancel-modal/cancel-modal',
            server: [
                'HTTP_HX_REQUEST' => 'true',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Cancel Reservation', $response->getBody());
        $this->assertStringContainsString('res-int-cancel-modal', $response->getBody());
        $this->assertStringContainsString('800,000', $response->getBody());
    }

    public function testCancelRouteExecutesCancellationAndSwapsContainers(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, source)
            VALUES ('res-int-cancel-exec', '1707', 'Exec Tester', 'exec@test.com', '+573001112233', '2026-12-22', '2026-12-27', 950000.00, 'confirmed', 'phone');
        ");

        $response = $this->dispatchAdmin(new Request(
            'POST',
            '/reservations/res-int-cancel-exec/cancel',
            post: [
                'csrf_token' => 'test-csrf-token',
                'reason' => 'Guest family emergency',
                'refund_type' => 'none',
            ],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'test-csrf-token',
            ]
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);
        $this->assertStringContainsString('modal-container', $response->getBody());
        $this->assertStringContainsString('drawer-container', $response->getBody());

        $stmt = $this->pdo->query("SELECT status, notes FROM reservations WHERE reservation_uid = 'res-int-cancel-exec'");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('cancelled', $row['status']);
        $this->assertStringContainsString('Guest family emergency', (string) $row['notes']);
    }
}
