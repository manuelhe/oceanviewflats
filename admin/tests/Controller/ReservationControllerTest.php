<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Controller\ReservationController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\InMemoryEmailSender;
use OceanViewFlats\Domain\Quote\Quote;
use OceanViewFlats\Domain\Quote\QuoteEngineInterface;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReservationControllerTest extends TestCase
{
    private PDO $pdo;
    private AdminReservationRepository $repository;
    private ViewRenderer $viewRenderer;
    private AuditLogger $auditLogger;
    /** @var MockObject&ReservationLedgerInterface */
    private MockObject $ledger;
    /** @var MockObject&QuoteEngineInterface */
    private MockObject $quoteEngine;
    /** @var MockObject&ConfirmationEmailRendererInterface */
    private MockObject $emailRenderer;
    private InMemoryEmailSender $emailSender;
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
        $this->auditLogger = new AuditLogger($this->pdo);
        $this->ledger = $this->createMock(ReservationLedgerInterface::class);
        $this->quoteEngine = $this->createMock(QuoteEngineInterface::class);
        $this->emailRenderer = $this->createMock(ConfirmationEmailRendererInterface::class);
        $this->emailSender = new InMemoryEmailSender();

        $this->controller = new ReservationController(
            $this->repository,
            $this->viewRenderer,
            $this->auditLogger,
            $this->ledger,
            $this->quoteEngine,
            $this->emailRenderer,
            $this->emailSender,
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

    public function testNewReservationHtmxReturnsModalPartial(): void
    {
        $request = new Request('GET', '/reservations/new', server: ['HTTP_HX_REQUEST' => 'true']);
        $response = $this->controller->newReservation($request, $this->session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Create Manual Reservation', $response->getBody());
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response->getBody());
    }

    public function testNewReservationDirectBrowserReturnsFullPageWithModal(): void
    {
        $request = new Request('GET', '/reservations/new');
        $response = $this->controller->newReservation($request, $this->session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Create Manual Reservation', $response->getBody());
        $this->assertStringContainsString('Reservations Management', $response->getBody());
        $this->assertStringContainsString('<!DOCTYPE html>', $response->getBody());
    }

    public function testQuotePreviewReturnsAvailabilityAndQuoteBreakdown(): void
    {
        $this->ledger->expects($this->once())
            ->method('isAvailable')
            ->with('1707', '2026-11-01', '2026-11-04')
            ->willReturn(true);

        $quote = new Quote(
            propertyId: '1707',
            checkIn: '2026-11-01',
            checkOut: '2026-11-04',
            nights: [],
            nightsCount: 3,
            accommodationTotalCop: 1500000.0,
            cleaningFeeCop: 150000.0,
            resortFeeCop: 100000.0,
            totalCop: 1750000.0,
            minimumStayRequired: 2,
            isValid: true
        );

        $this->quoteEngine->expects($this->once())
            ->method('quote')
            ->with('1707', '2026-11-01', '2026-11-04')
            ->willReturn($quote);

        $request = new Request('POST', '/reservations/quote-preview', post: [
            'property_id' => '1707',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-04',
            'source' => 'bank_transfer',
        ]);
        $response = $this->controller->quotePreview($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Available (3 Nights)', $body);
        $this->assertStringContainsString('$1,750,000 COP', $body);
        $this->assertStringContainsString('$1,500,000', $body);
    }

    public function testQuotePreviewForOwnerStayZeroesTotal(): void
    {
        $this->ledger->method('isAvailable')->willReturn(true);
        $quote = new Quote(
            propertyId: '1707',
            checkIn: '2026-11-01',
            checkOut: '2026-11-04',
            nights: [],
            nightsCount: 3,
            accommodationTotalCop: 1500000.0,
            cleaningFeeCop: 150000.0,
            resortFeeCop: 100000.0,
            totalCop: 1750000.0,
            minimumStayRequired: 2,
            isValid: true
        );
        $this->quoteEngine->method('quote')->willReturn($quote);

        $request = new Request('POST', '/reservations/quote-preview', post: [
            'property_id' => '1707',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-04',
            'source' => 'owner_stay',
        ]);
        $response = $this->controller->quotePreview($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('$0 COP', $body);
        $this->assertStringContainsString('Owner Stay automatically zeroes total pricing', $body);
    }

    public function testQuotePreviewReturnsConflictWarningWhenUnavailable(): void
    {
        $this->ledger->method('isAvailable')->willReturn(false);
        $this->ledger->method('getConflictReasons')
            ->willReturn(['Direct reservation conflict: res-1']);

        $request = new Request('POST', '/reservations/quote-preview', post: [
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
        ]);
        $response = $this->controller->quotePreview($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Selected Dates Are Unavailable', $body);
        $this->assertStringContainsString('href="/reservations/res-1"', $body);
    }

    public function testCreateManualReservationValidatesInputAndReturns422(): void
    {
        // Bad dates: checkout <= checkin
        $request1 = new Request('POST', '/reservations/create-manual', post: [
            'property_id' => '1606',
            'check_in' => '2026-11-05',
            'check_out' => '2026-11-01',
            'total_price' => 1000000,
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'guest_phone' => '+573001234567',
        ]);
        $response = $this->controller->createManual($request1, $this->session);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Check-out date must be strictly after check-in date', $response->getBody());

        // Bad email
        $request2 = new Request('POST', '/reservations/create-manual', post: [
            'property_id' => '1606',
            'check_in' => '2026-11-01',
            'check_out' => '2026-11-05',
            'total_price' => 1000000,
            'guest_name' => 'John Doe',
            'guest_email' => 'invalid-email',
            'guest_phone' => '+573001234567',
        ]);
        $response2 = $this->controller->createManual($request2, $this->session);
        $this->assertSame(422, $response2->getStatusCode());
        $this->assertStringContainsString('valid guest email', $response2->getBody());
    }

    public function testCreateManualReservationRejectsLedgerConflictWith422(): void
    {
        $this->ledger->method('isAvailable')->willReturn(false);

        $request = new Request('POST', '/reservations/create-manual', post: [
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'total_price' => 1200000,
            'guest_name' => 'Overlapping Stay',
            'guest_email' => 'overlap@example.com',
            'guest_phone' => '+573009998877',
        ]);
        $response = $this->controller->createManual($request, $this->session);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Selected dates conflict', $response->getBody());
    }

    public function testCreateManualReservationSuccessDispatchesEmailAndEmitsHxLocation(): void
    {
        $this->ledger->method('isAvailable')->willReturn(true);
        $this->emailRenderer->method('renderGuestSubject')->willReturn('Your Stay Confirmation');
        $this->emailRenderer->method('renderGuestConfirmationHtml')->willReturn('<p>Welcome</p>');

        $request = new Request('POST', '/reservations/create-manual', post: [
            'property_id' => '1707',
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-05',
            'source' => 'bank_transfer',
            'total_price' => 2400000,
            'guest_name' => 'Elena Rostova',
            'guest_email' => 'elena@example.com',
            'guest_phone' => '+573008889900',
            'notes' => 'Transfer receipt Bancolombia #12345',
            'pre_mark_registry' => '1',
            'send_confirmation_email' => '1',
        ], server: ['HTTP_HX_REQUEST' => 'true']);
        $response = $this->controller->createManual($request, $this->session);

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('HX-Location', $headers);
        $this->assertMatchesRegularExpression('#^/reservations/res-man-[a-f0-9]+$#', $headers['HX-Location']);

        // Verify database row
        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE guest_email = :email');
        $stmt->execute(['email' => 'elena@example.com']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('1707', $row['property_id']);
        $this->assertSame('Elena Rostova', $row['guest_name']);
        $this->assertSame('bank_transfer', $row['source']);
        $this->assertSame(1, (int) $row['registry_completed']);
        $this->assertNotNull($row['registry_completed_at']);
        $this->assertMatchesRegularExpression('/^0[0-9]{6}#$/', $row['door_code']);

        // Verify audit log
        $logStmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = :action AND entity_id = :uid');
        $logStmt->execute(['action' => 'manual_reservation_created', 'uid' => $row['reservation_uid']]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);

        // Verify email was sent
        $sent = $this->emailSender->getSentMessages();
        $this->assertCount(1, $sent);
        $this->assertSame('elena@example.com', $sent[0]['to']);
    }

    public function testCreateManualReservationEmailFailureDoesNotRollback(): void
    {
        $this->ledger->method('isAvailable')->willReturn(true);
        $this->emailRenderer->method('renderGuestSubject')->willReturn('Subject');
        $this->emailRenderer->method('renderGuestConfirmationHtml')->willReturn('<p>Body</p>');
        $this->emailSender->setShouldFail(true);

        $request = new Request('POST', '/reservations/create-manual', post: [
            'property_id' => '1606',
            'check_in' => '2026-12-10',
            'check_out' => '2026-12-12',
            'total_price' => 800000,
            'guest_name' => 'Failing Email Guest',
            'guest_email' => 'fail@example.com',
            'guest_phone' => '+573001110000',
            'send_confirmation_email' => '1',
        ], server: ['HTTP_HX_REQUEST' => 'true']);
        $response = $this->controller->createManual($request, $this->session);

        $this->assertSame(200, $response->getStatusCode());

        // DB row still created
        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE guest_email = :email');
        $stmt->execute(['email' => 'fail@example.com']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);

        // Failure logged in audit log
        $logStmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = :action AND entity_id = :uid');
        $logStmt->execute(['action' => 'email_delivery_failed', 'uid' => $row['reservation_uid']]);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
    }

    public function testCompleteRegistryMarksCompletedGeneratesDoorCodeIfNullAndEmitsHxTrigger(): void
    {
        // res-2 currently has registry_completed = 0 and door_code = NULL
        $request = (new Request('POST', '/reservations/res-2/registry/complete'))
            ->withAttribute('uid', 'res-2');
        $response = $this->controller->completeRegistry($request, $this->session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        // Check updated DB
        $res = $this->repository->findReservationByUid('res-2');
        $this->assertNotNull($res);
        $this->assertSame(1, (int) $res['registry_completed']);
        $this->assertNotNull($res['registry_completed_at']);
        $this->assertMatchesRegularExpression('/^0[0-9]{6}#$/', $res['door_code']);

        // Check audit log
        $logStmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = :action AND entity_id = :uid');
        $logStmt->execute(['action' => 'registry_manual_complete', 'uid' => 'res-2']);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
    }

    public function testOverrideDoorCodeRequiresConfirmedStatus(): void
    {
        // res-2 has status = 'pending_payment'
        $request = (new Request('POST', '/reservations/res-2/door-code/override', post: ['door_code' => '0123456#']))
            ->withAttribute('uid', 'res-2');
        $response = $this->controller->overrideDoorCode($request, $this->session);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('strictly restricted to confirmed reservations', $response->getBody());
    }

    public function testOverrideDoorCodeUpdatesPinAndAuditLog(): void
    {
        // res-1 has status = 'confirmed'
        $request = (new Request('POST', '/reservations/res-1/door-code/override', post: ['door_code' => '0887766#']))
            ->withAttribute('uid', 'res-1');
        $response = $this->controller->overrideDoorCode($request, $this->session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertSame('0887766#', $res['door_code']);

        // Check audit log
        $logStmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = :action AND entity_id = :uid ORDER BY id DESC LIMIT 1');
        $logStmt->execute(['action' => 'pin_override', 'uid' => 'res-1']);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
        $this->assertStringContainsString('0887766#', (string) $log['payload_after']);
    }

    public function testRegenerateDoorCodeGeneratesNewPin(): void
    {
        // res-1 has status = 'confirmed' and door_code = '1234#'
        $request = (new Request('POST', '/reservations/res-1/door-code/regenerate'))
            ->withAttribute('uid', 'res-1');
        $response = $this->controller->regenerateDoorCode($request, $this->session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('reservationUpdated', $response->getHeaders()['HX-Trigger'] ?? null);

        $res = $this->repository->findReservationByUid('res-1');
        $this->assertNotNull($res);
        $this->assertNotSame('1234#', $res['door_code']);
        $this->assertMatchesRegularExpression('/^0[0-9]{6}#$/', $res['door_code']);

        // Check audit log
        $logStmt = $this->pdo->prepare('SELECT * FROM admin_audit_logs WHERE action = :action AND entity_id = :uid ORDER BY id DESC LIMIT 1');
        $logStmt->execute(['action' => 'pin_regenerate', 'uid' => 'res-1']);
        $log = $logStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($log);
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
