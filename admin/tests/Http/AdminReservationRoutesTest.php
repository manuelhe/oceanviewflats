<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminReservationRoutesTest extends TestCase
{
    private PDO $pdo;
    private Router $router;

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
        ');
    }

    private function configureRouter(): void
    {
        $viewsPath = dirname(__DIR__, 2) . '/src/Views';
        $viewRenderer = new ViewRenderer($viewsPath);
        $reservationRepo = new AdminReservationRepository($this->pdo);

        $reservationController = new ReservationController(
            repository: $reservationRepo,
            viewRenderer: $viewRenderer,
            publicSiteUrl: 'https://oceanviewflats.com'
        );

        $this->router = new Router(
            sessionMiddleware: new SessionMiddleware(),
            csrfMiddleware: new CsrfMiddleware(),
            authMiddleware: new AuthMiddleware()
        );

        $this->router->get('/reservations', [$reservationController, 'list'])
            ->get('/reservations/{uid}', [$reservationController, 'show'])
            ->get('/reservations/{uid}/registry', [$reservationController, 'showRegistry']);
    }

    private function dispatchAdmin(Request $request): Response
    {
        $session = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Operator Manuel',
            'admin_user_email' => 'operator@test.com',
            'admin_user_role' => 'admin',
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
}
