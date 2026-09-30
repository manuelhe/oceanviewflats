<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use DateTimeImmutable;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Controller\CalendarBlockController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Reservation\ChannelBlock;
use OceanViewFlats\Domain\Reservation\InMemoryChannelBlockSource;
use OceanViewFlats\Domain\Reservation\InMemoryReservationRepository;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationLedger;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PDO;
use PHPUnit\Framework\TestCase;

final class CalendarBlockControllerTest extends TestCase
{
    private PDO $pdo;
    private AdminCalendarBlockRepository $blockRepo;
    private InMemoryReservationRepository $reservationRepo;
    private InMemoryChannelBlockSource $channelBlockSource;
    private ReservationLedger $ledger;
    private CalendarBlockController $controller;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabaseWithDefaultAdmin();

        $this->blockRepo = new AdminCalendarBlockRepository($this->pdo);
        $this->reservationRepo = new InMemoryReservationRepository();
        $this->channelBlockSource = new InMemoryChannelBlockSource();
        $this->ledger = new ReservationLedger(
            repository: $this->reservationRepo,
            channelBlockSource: $this->channelBlockSource
        );

        $viewRenderer = new ViewRenderer(dirname(__DIR__, 2) . '/src/Views');
        $auditLogger = new AuditLogger($this->pdo);

        $this->controller = new CalendarBlockController(
            blockRepository: $this->blockRepo,
            ledger: $this->ledger,
            viewRenderer: $viewRenderer,
            auditLogger: $auditLogger
        );
    }

    public function testIndexRendersFullPage(): void
    {
        $session = [
            'admin_user_id' => 1,
            'admin_email' => 'admin@oceanviewflats.com',
            'csrf_token' => 'test_token',
        ];

        $request = new Request(method: 'GET', uri: '/calendar-blocks');
        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Maintenance & Calendar Holds', $response->getBody());
        $this->assertStringContainsString('+ Add Maintenance Hold', $response->getBody());
    }

    public function testIndexRendersHtmxPartial(): void
    {
        $session = [
            'admin_user_id' => 1,
            'csrf_token' => 'test_token',
        ];

        $request = new Request(
            method: 'GET',
            uri: '/calendar-blocks',
            query: ['property_id' => '1606', 'filter' => 'upcoming'],
            server: ['HTTP_HX_REQUEST' => 'true']
        );
        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('No maintenance holds found', $response->getBody());
        $this->assertStringNotContainsString('<main class=', $response->getBody());
    }

    public function testNewHoldRendersModal(): void
    {
        $session = ['csrf_token' => 'test_token'];
        $request = new Request(method: 'GET', uri: '/calendar-blocks/new', query: ['property_id' => '1707']);
        $response = $this->controller->newHold($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Add Maintenance Hold', $response->getBody());
        $this->assertStringContainsString('value="1707" selected', $response->getBody());
    }

    public function testCreateRejectsInvalidCsrf(): void
    {
        $session = ['csrf_token' => 'valid_token'];
        $request = new Request(
            method: 'POST',
            uri: '/calendar-blocks',
            post: ['csrf_token' => 'wrong_token', 'property_id' => '1606']
        );

        $response = $this->controller->create($request, $session);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateRejectsInvalidDates(): void
    {
        $session = ['csrf_token' => 'valid_token'];
        $request = new Request(
            method: 'POST',
            uri: '/calendar-blocks',
            post: [
                'csrf_token' => 'valid_token',
                'property_id' => '1606',
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-05',
                'reason' => 'Fix pipe',
            ]
        );

        $response = $this->controller->create($request, $session);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('must be strictly after start date', $response->getBody());
    }

    public function testCreateRejectsCollisionWithDirectReservation(): void
    {
        $now = new DateTimeImmutable('2026-10-01 10:00:00');

        // Existing direct reservation in ledger: Nov 10 to Nov 15
        $this->reservationRepo->save(new Reservation(
            reservationUid: 'ovf_active_direct',
            propertyId: '1606',
            guestName: 'Direct Guest',
            guestEmail: 'guest@example.com',
            guestPhone: '+573101112233',
            checkIn: '2026-11-10',
            checkOut: '2026-11-15',
            totalPrice: 1200000.0,
            status: ReservationStatus::CONFIRMED,
            createdAt: $now
        ));

        $session = ['csrf_token' => 'valid_token', 'admin_user_id' => 1];
        $request = new Request(
            method: 'POST',
            uri: '/calendar-blocks',
            post: [
                'csrf_token' => 'valid_token',
                'property_id' => '1606',
                'start_date' => '2026-11-12',
                'end_date' => '2026-11-18',
                'reason' => 'Emergency roof repair',
            ]
        );

        $response = $this->controller->create($request, $session);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Collides with active direct reservation ovf_active_direct', $response->getBody());
    }

    public function testCreateRejectsOverlapWithExistingMaintenanceBlock(): void
    {
        $this->blockRepo->createBlock('1606', '2026-11-10', '2026-11-15', 'Existing block', 1);

        $session = ['csrf_token' => 'valid_token', 'admin_user_id' => 1];
        $request = new Request(
            method: 'POST',
            uri: '/calendar-blocks',
            post: [
                'csrf_token' => 'valid_token',
                'property_id' => '1606',
                'start_date' => '2026-11-12',
                'end_date' => '2026-11-18',
                'reason' => 'Another repair',
            ]
        );

        $response = $this->controller->create($request, $session);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Proposed dates overlap with an existing maintenance hold', $response->getBody());
    }

    public function testCreatePermitsOverlapWithChannelBlockAndLogsAudit(): void
    {
        // Ephemeral OTA channel block on 1606
        $this->channelBlockSource->addBlock(new ChannelBlock(
            propertyId: '1606',
            startDate: '2026-11-20',
            endDate: '2026-11-25',
            source: 'airbnb'
        ));

        $session = [
            'csrf_token' => 'valid_token',
            'admin_user_id' => 1,
            'admin_email' => 'admin@oceanviewflats.com',
        ];
        $request = new Request(
            method: 'POST',
            uri: '/calendar-blocks',
            post: [
                'csrf_token' => 'valid_token',
                'property_id' => '1606',
                'start_date' => '2026-11-20',
                'end_date' => '2026-11-25',
                'reason' => 'Annual HVAC Maintenance',
            ],
            server: ['HTTP_HX_REQUEST' => 'true']
        );

        $response = $this->controller->create($request, $session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('blockSaved', $response->getHeader('HX-Trigger'));

        // Verify block exists in DB
        $blocks = $this->blockRepo->getBlocks('1606', 'all');
        $this->assertCount(1, $blocks);
        $this->assertSame('Annual HVAC Maintenance', $blocks[0]['reason']);

        // Verify audit log record
        $stmt = $this->pdo->query("SELECT * FROM admin_audit_logs WHERE action = 'calendar_block_created'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $logs);
        $this->assertSame('calendar_block', $logs[0]['entity_type']);
    }

    public function testDeleteConcludedPastBlockFailsWith422(): void
    {
        // Create past block: ended yesterday
        $yesterday = date('Y-m-d', strtotime('-2 days'));
        $pastEnd = date('Y-m-d', strtotime('-1 day'));
        $id = $this->blockRepo->createBlock('1606', $yesterday, $pastEnd, 'Concluded inspection', 1);

        $session = ['csrf_token' => 'valid_token', 'admin_user_id' => 1];
        $request = new Request(
            method: 'DELETE',
            uri: "/calendar-blocks/{$id}",
            post: ['csrf_token' => 'valid_token'],
            attributes: ['id' => $id]
        );

        $response = $this->controller->delete($request, $session);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Concluded historical maintenance blocks cannot be deleted', $response->getBody());

        // Verify block was NOT deleted
        $this->assertNotNull($this->blockRepo->findById($id));
    }

    public function testDeleteActiveUpcomingBlockSucceedsAndLogsAudit(): void
    {
        $futureStart = date('Y-m-d', strtotime('+5 days'));
        $futureEnd = date('Y-m-d', strtotime('+10 days'));
        $id = $this->blockRepo->createBlock('1606', $futureStart, $futureEnd, 'Future paint touchup', 1);

        $session = [
            'csrf_token' => 'valid_token',
            'admin_user_id' => 1,
            'admin_email' => 'admin@oceanviewflats.com',
        ];
        $request = new Request(
            method: 'DELETE',
            uri: "/calendar-blocks/{$id}",
            post: ['csrf_token' => 'valid_token'],
            attributes: ['id' => $id],
            server: ['HTTP_HX_REQUEST' => 'true']
        );

        $response = $this->controller->delete($request, $session);
        $this->assertSame(200, $response->getStatusCode());

        // Verify block deleted from DB
        $this->assertNull($this->blockRepo->findById($id));

        // Verify audit log record
        $stmt = $this->pdo->query("SELECT * FROM admin_audit_logs WHERE action = 'calendar_block_deleted'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $logs);
        $this->assertSame('calendar_block', $logs[0]['entity_type']);
        $this->assertSame((string) $id, $logs[0]['entity_id']);
    }
}
