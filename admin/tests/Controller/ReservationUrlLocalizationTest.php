<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\GuestLifecycleFulfillmentServiceInterface;
use OceanViewFlats\Domain\Fulfillment\InMemoryEmailSender;
use OceanViewFlats\Domain\Quote\QuoteEngineInterface;
use OceanViewFlats\Domain\Reservation\PdoReservationRepository;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use OceanViewFlats\Domain\Reservation\Search\PdoReservationSearchAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReservationUrlLocalizationTest extends TestCase
{
    private PDO $pdo;
    private ReservationController $controller;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabase();
        $repository = new PdoReservationRepository($this->pdo);
        $search = new PdoReservationSearchAdapter($this->pdo);
        $viewRenderer = new ViewRenderer(dirname(__DIR__, 2) . '/src/Views');
        $auditLogger = new AuditLogger($this->pdo);
        $ledger = $this->createMock(ReservationLedgerInterface::class);
        $quoteEngine = $this->createMock(QuoteEngineInterface::class);
        $emailSender = new InMemoryEmailSender();
        $lifecycleService = $this->createMock(GuestLifecycleFulfillmentServiceInterface::class);
        $emailRenderer = $this->createMock(ConfirmationEmailRendererInterface::class);

        $this->controller = new ReservationController(
            repository: $repository,
            search: $search,
            viewRenderer: $viewRenderer,
            auditLogger: $auditLogger,
            ledger: $ledger,
            quoteEngine: $quoteEngine,
            emailSender: $emailSender,
            lifecycleService: $lifecycleService,
            publicSiteUrl: 'https://oceanviewflats.com',
            refundClient: null,
            cancellationEmailRenderer: null,
            pdo: $this->pdo
        );

        $this->seedTestData();
    }

    public function testRegistryModalUrlsUseStaticPageRoutesWithoutLangQueryParam(): void
    {
        // 1. English reservation (res-en)
        $reqEn = (new Request('GET', '/reservations/res-en/registry', server: ['HTTP_HX_REQUEST' => 'true']))
            ->withAttribute('uid', 'res-en');
        $session = ['user_id' => 1];
        $htmlEn = $this->controller->showRegistry($reqEn, $session)->getBody();

        $this->assertStringContainsString(
            'https://oceanviewflats.com/registry/index.html?property=1606&amp;check_in=2026-10-10&amp;check_out=2026-10-15&amp;code=res-en',
            $htmlEn,
            'English registry URL must point to /registry/index.html with params and no lang query'
        );
        $this->assertStringContainsString(
            'https://oceanviewflats.com/guide/index.html?code=res-en',
            $htmlEn,
            'English guide URL must point to /guide/index.html?code=res-en'
        );
        $this->assertStringNotContainsString('lang=en', $htmlEn);
        $this->assertStringNotContainsString('/registry/?', $htmlEn);
        $this->assertStringNotContainsString('/guide/?', $htmlEn);

        // 2. Spanish reservation (res-es)
        $reqEs = (new Request('GET', '/reservations/res-es/registry', server: ['HTTP_HX_REQUEST' => 'true']))
            ->withAttribute('uid', 'res-es');
        $htmlEs = $this->controller->showRegistry($reqEs, $session)->getBody();

        $this->assertStringContainsString(
            'https://oceanviewflats.com/registry/es.html?property=1707&amp;check_in=2026-11-01&amp;check_out=2026-11-05&amp;code=res-es',
            $htmlEs,
            'Spanish registry URL must point to /registry/es.html with params and no lang query'
        );
        $this->assertStringContainsString(
            'https://oceanviewflats.com/guide/es.html?code=res-es',
            $htmlEs,
            'Spanish guide URL must point to /guide/es.html?code=res-es'
        );
        $this->assertStringNotContainsString('lang=es', $htmlEs);
        $this->assertStringNotContainsString('/registry/?', $htmlEs);
        $this->assertStringNotContainsString('/guide/?', $htmlEs);
    }

    public function testDetailDrawerGuideLinkUsesStaticPageRoute(): void
    {
        $session = ['user_id' => 1];

        // English reservation drawer
        $reqEn = (new Request('GET', '/reservations/res-en', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_TARGET' => 'drawer-container']))
            ->withAttribute('uid', 'res-en');
        $htmlEn = $this->controller->show($reqEn, $session)->getBody();
        $this->assertStringContainsString('https://oceanviewflats.com/guide/index.html?code=res-en', $htmlEn);

        // Spanish reservation drawer
        $reqEs = (new Request('GET', '/reservations/res-es', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_TARGET' => 'drawer-container']))
            ->withAttribute('uid', 'res-es');
        $htmlEs = $this->controller->show($reqEs, $session)->getBody();
        $this->assertStringContainsString('https://oceanviewflats.com/guide/es.html?code=res-es', $htmlEs);
    }

    public function testAirbnbChatDispatchUsesStaticLocalizedPageRoutes(): void
    {
        $session = ['user_id' => 1];

        // Stage 1 (pending registry)
        $reqStage1 = (new Request('GET', '/reservations/res-abnb-pending', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_TARGET' => 'drawer-container']))
            ->withAttribute('uid', 'res-abnb-pending');
        $htmlStage1 = $this->controller->show($reqStage1, $session)->getBody();

        // data-text-es must contain Spanish static registry URL
        $this->assertStringContainsString('https://oceanviewflats.com/registry/es.html?code=res-abnb-pending', $htmlStage1);
        // data-text-en must contain English static registry URL
        $this->assertStringContainsString('https://oceanviewflats.com/registry/index.html?code=res-abnb-pending', $htmlStage1);
        $this->assertStringNotContainsString('/registry/?code=', $htmlStage1);

        // Stage 2 (completed registry)
        $reqStage2 = (new Request('GET', '/reservations/res-abnb-complete', server: ['HTTP_HX_REQUEST' => 'true', 'HTTP_HX_TARGET' => 'drawer-container']))
            ->withAttribute('uid', 'res-abnb-complete');
        $htmlStage2 = $this->controller->show($reqStage2, $session)->getBody();

        // data-text-es must contain Spanish static guide URL
        $this->assertStringContainsString('https://oceanviewflats.com/guide/es.html?code=res-abnb-complete', $htmlStage2);
        // data-text-en must contain English static guide URL
        $this->assertStringContainsString('https://oceanviewflats.com/guide/index.html?code=res-abnb-complete', $htmlStage2);
        $this->assertStringNotContainsString('/guide/?code=', $htmlStage2);
    }

    private function seedTestData(): void
    {
        $this->pdo->exec("
            INSERT INTO admin_users (id, email, password_hash, name, role)
            VALUES (1, 'operator@oceanviewflats.com', 'dummy_hash', 'Operator Manuel', 'admin');

            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, source, registry_completed,
                door_code, mercadopago_payment_id, payment_status, created_at, lang
            ) VALUES 
            ('res-en', '1606', 'Alice English', 'alice@example.com', '+1234567890', '2026-10-10', '2026-10-15', 1500000.00, 'pending_payment', 'direct', 0, NULL, NULL, 'pending', '2026-09-02 12:00:00', 'en'),
            ('res-es', '1707', 'Carlos Espanol', 'carlos@example.com', '+573007778899', '2026-11-01', '2026-11-05', 1800000.00, 'confirmed', 'direct', 0, '5678#', NULL, 'offline', '2026-09-03 12:00:00', 'es'),
            ('res-abnb-pending', '1606', 'David Airbnb', 'david@example.com', '+1987654321', '2026-12-01', '2026-12-05', 2000000.00, 'confirmed', 'airbnb', 0, NULL, NULL, 'approved', '2026-09-04 12:00:00', 'es'),
            ('res-abnb-complete', '1707', 'Elena Airbnb', 'elena@example.com', '+1987654322', '2026-12-10', '2026-12-15', 2500000.00, 'confirmed', 'airbnb', 1, '9999#', NULL, 'approved', '2026-09-05 12:00:00', 'es');
        ");
    }
}
