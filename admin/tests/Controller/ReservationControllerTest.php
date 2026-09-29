<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReservationControllerTest extends TestCase
{
    private PDO $pdo;
    private AdminReservationRepository $repository;
    private ViewRenderer $viewRenderer;
    private ReservationController $controller;

    /**
     * @var array<string, mixed>
     */
    private array $session;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

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

        $this->seedDatabase();

        $this->repository = new AdminReservationRepository($this->pdo);
        $this->viewRenderer = new ViewRenderer(dirname(__DIR__, 2) . '/src/Views');
        $this->controller = new ReservationController(
            $this->repository,
            $this->viewRenderer,
            'https://oceanviewflats.com'
        );

        $this->session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Operator Manuel',
            'admin_user_email' => 'operator@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'test-csrf-token-xyz',
        ];
    }

    /**
     * @param array<string, mixed>|null $session
     */
    private function executeIndex(?Request $request = null, ?array $session = null): Response
    {
        $req = $request ?? new Request('GET', '/reservations');
        if ($session !== null) {
            $sess = $session;
            return $this->controller->list($req, $sess);
        }
        return $this->controller->list($req, $this->session);
    }

    private function executeShow(string $uid, bool $isHtmx = false): Response
    {
        $server = $isHtmx ? ['HTTP_HX_REQUEST' => 'true'] : [];
        $request = (new Request('GET', '/reservations/' . $uid, server: $server))
            ->withAttribute('uid', $uid);
        return $this->controller->show($request, $this->session);
    }

    private function executeRegistry(string $uid, bool $isHtmx = true): Response
    {
        $server = $isHtmx ? ['HTTP_HX_REQUEST' => 'true'] : [];
        $request = (new Request('GET', '/reservations/' . $uid . '/registry', server: $server))
            ->withAttribute('uid', $uid);
        return $this->controller->showRegistry($request, $this->session);
    }

    public function testIndexReturnsFullPageForStandardBrowserRequest(): void
    {
        $response = $this->executeIndex();
        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getBody();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Reservations Management', $html);
        $this->assertStringContainsString('id="search-input"', $html);
        $this->assertStringContainsString('Alice Smith', $html);
        $this->assertStringContainsString('Bob Jones', $html);
    }

    public function testIndexReturnsTablePartialForHtmxRequest(): void
    {
        $partial = $this->executeIndex(new Request('GET', '/reservations', server: ['HTTP_HX_REQUEST' => 'true']));

        $this->assertSame(200, $partial->getStatusCode());
        $content = $partial->getBody();
        $this->assertStringNotContainsString('<!DOCTYPE html>', $content);
        $this->assertStringNotContainsString('<header', $content);
        $this->assertStringContainsString('Alice Smith', $content);
        $this->assertStringContainsString('Bob Jones', $content);
        $this->assertStringContainsString('Showing', $content);
    }

    public function testIndexAppliesFiltersCorrectly(): void
    {
        $filteredReq = new Request(
            'GET',
            '/reservations',
            query: ['property_id' => '1707'],
            server: ['HTTP_HX_REQUEST' => 'true']
        );
        $html = $this->executeIndex($filteredReq)->getBody();

        $this->assertStringContainsString('Carlos Gomez', $html);
        $this->assertStringNotContainsString('Alice Smith', $html);
    }

    public function testShowReturnsDrawerPartialForHtmxRequest(): void
    {
        $html = $this->executeShow('res-1', isHtmx: true)->getBody();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('slide-over-title', $html);
        $this->assertStringContainsString('Alice Smith', $html);
        $this->assertStringContainsString('1234#', $html);
        $this->assertStringContainsString('Operational Audit Trail', $html);
        $this->assertStringContainsString('pin_override', $html);
    }

    public function testShowReturnsFullPageWithOpenDrawerForBrowserRequest(): void
    {
        $html = $this->executeShow('res-1', isHtmx: false)->getBody();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Reservations Management', $html);
        $this->assertStringContainsString('slide-over-title', $html);
        $this->assertStringContainsString('Alice Smith', $html);
    }

    public function testShowReturns404AlertForHtmxNonExistentReservation(): void
    {
        $response = $this->executeShow('unknown-uid', isHtmx: true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Reservation not found', $response->getBody());
    }

    public function testShowRedirectsForBrowserNonExistentReservation(): void
    {
        $response = $this->executeShow('unknown-uid', isHtmx: false);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/reservations', $response->getHeaders()['Location'] ?? null);
    }

    public function testShowRegistryReturnsCompletedModal(): void
    {
        $html = $this->executeRegistry('res-1')->getBody();

        $this->assertStringContainsString('Guest Registry Dossier', $html);
        $this->assertStringContainsString('Bob Smith', $html);
        $this->assertStringContainsString('ABC-123', $html);
        $this->assertStringContainsString('192.168.1.1', $html);
    }

    public function testShowRegistryReturnsPendingModalWithCopyableLink(): void
    {
        $html = $this->executeRegistry('res-2')->getBody();

        $this->assertStringContainsString('Guest Registry Pending', $html);
        $this->assertStringContainsString('https://oceanviewflats.com/registry/?property=1606&amp;code=res-2', $html);
        $this->assertStringContainsString('Copy Link', $html);
    }

    public function testShowRegistryReturns404WhenReservationMissing(): void
    {
        $response = $this->executeRegistry('nonexistent');

        $this->assertSame(404, $response->getStatusCode());
    }

    private function seedDatabase(): void
    {
        $this->pdo->exec("
            INSERT INTO admin_users (id, email, password_hash, name, role)
            VALUES (1, 'operator@oceanviewflats.com', 'dummy_hash', 'Operator Manuel', 'admin');

            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, source, registry_completed,
                door_code, created_at
            ) VALUES 
            ('res-1', '1606', 'Alice Smith', 'alice@example.com', '+573001112233', '2026-10-01', '2026-10-05', 1200000.00, 'confirmed', 'web', 1, '1234#', '2026-09-01 12:00:00'),
            ('res-2', '1606', 'Bob Jones', 'bob@example.com', '+573004445566', '2026-10-10', '2026-10-15', 1500000.00, 'pending_payment', 'cash', 0, NULL, '2026-09-02 12:00:00'),
            ('res-3', '1707', 'Carlos Gomez', 'carlos@example.com', '+573007778899', '2026-10-20', '2026-10-25', 1800000.00, 'confirmed', 'manual_override', 0, '5678#', '2026-09-03 12:00:00');

            INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, created_at)
            VALUES (1, 'pin_override', 'reservation', 'res-1', '{\"door_code\": \"1111#\"}', '{\"door_code\": \"1234#\"}', '127.0.0.1', '2026-09-29 10:00:00');

            INSERT INTO guest_registries (reservation_uid, property_id, check_in, check_out, guest_count, guests_payload, car_plates, car_model, ip_address)
            VALUES ('res-1', '1606', '2026-10-01', '2026-10-05', 1, '[{\"full_name\":\"Bob Smith\",\"doc_type\":\"CC\",\"doc_number\":\"12345678\",\"is_primary\":true}]', 'ABC-123', 'Toyota Corolla', '192.168.1.1');
        ");
    }
}
