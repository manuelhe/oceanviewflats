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
        $body = $response->getBody();

        // 1. Header and Page Titles
        $this->assertStringContainsString('Maintenance & Calendar Holds', $body);
        $this->assertStringContainsString('id="calendar-blocks-header-actions"', $body);
        $this->assertStringContainsString('+ Add Maintenance Hold', $body);
        $this->assertStringContainsString('hx-get="/calendar-blocks/new?property_id=all&filter=upcoming"', $body);

        // 2. Channel sync panel is decoupled and outside view container
        $this->assertStringContainsString('id="channel-sync-panel"', $body);
        $this->assertStringContainsString('Inbound Channel Sync', $body);

        // 3. Dynamic blocks view container
        $this->assertStringContainsString('id="blocks-view-container"', $body);
        $this->assertStringContainsString('Property:', $body);
        $this->assertStringNotContainsString('Unit:', $body);

        // 4. Property Pills styling and aria-current
        // All Properties is active
        $this->assertMatchesRegularExpression(
            '/All Properties\s*<\/a>/s',
            $body
        );
        $this->assertStringContainsString('bg-indigo-600 text-white shadow-2xs', $body);
        $this->assertStringContainsString('aria-current="page"', $body);
        $this->assertStringContainsString('bg-gray-100 text-gray-700 hover:bg-gray-200', $body);

        // 5. Status Pills styling and aria-current
        // Active & Upcoming is active
        $this->assertStringContainsString('bg-indigo-50 text-indigo-700 border border-indigo-200', $body);
        $this->assertStringContainsString('bg-gray-50 text-gray-600 hover:bg-gray-100 border border-gray-200', $body);

        // 6. HTMX target and push-url bindings on pills
        $this->assertStringContainsString('hx-target="#blocks-view-container"', $body);
        $this->assertStringContainsString('hx-swap="outerHTML"', $body);
        $this->assertStringContainsString('hx-push-url="true"', $body);

        // 7. ARIA roles on pills
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*All Properties\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Apartment 1606\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Apartment 1707\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Active & Upcoming\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Concluded \(Past\)\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*All History\s*<\/a>/s', $body);

        // 8. Sibling query state preserved in default links
        $this->assertStringContainsString('href="/calendar-blocks?property_id=1606&filter=upcoming"', $body);
        $this->assertStringContainsString('href="/calendar-blocks?property_id=all&filter=past"', $body);
    }

    public function testIndexRendersChannelSyncPanelWithInjectedSyncService(): void
    {
        $status1606 = new \OceanViewFlats\Domain\Reservation\ChannelSyncStatus(
            propertyId: '1606',
            status: \OceanViewFlats\Domain\Reservation\ChannelSyncStatus::STATUS_HEALTHY,
            lastSyncedAt: date('c', time() - 120),
            lastAttemptedAt: date('c', time() - 120),
            httpCode: 200,
            blockedNightsCount: 9,
            errorMessage: null
        );

        $fakeSyncService = new FakeCalendarBlockSyncService(['1606' => $status1606]);

        $controller = new CalendarBlockController(
            blockRepository: $this->blockRepo,
            ledger: $this->ledger,
            viewRenderer: new ViewRenderer(dirname(__DIR__, 2) . '/src/Views'),
            auditLogger: new AuditLogger($this->pdo),
            syncService: $fakeSyncService
        );

        $session = [
            'admin_user_id' => 1,
            'admin_email' => 'admin@oceanviewflats.com',
            'csrf_token' => 'test_token',
        ];

        $request = new Request(method: 'GET', uri: '/calendar-blocks');
        $response = $controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('id="channel-sync-panel"', $response->getBody());
        $this->assertStringContainsString('9', $response->getBody());
        $this->assertStringContainsString('blocked nights', $response->getBody());
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
            query: ['property_id' => '1606', 'filter' => 'past'],
            server: ['HTTP_HX_REQUEST' => 'true']
        );
        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        // Must return dynamic blocks container and OOB header actions
        $this->assertStringContainsString('id="blocks-view-container"', $body);
        $this->assertStringContainsString('id="calendar-blocks-header-actions" hx-swap-oob="outerHTML"', $body);
        $this->assertStringContainsString('hx-get="/calendar-blocks/new?property_id=1606&filter=past"', $body);

        // Channel sync panel and master layout must not be present in HTMX partial
        $this->assertStringNotContainsString('id="channel-sync-panel"', $body);
        $this->assertStringNotContainsString('<main class=', $body);

        // Property 1606 and Past status must be visually active
        $this->assertStringContainsString('href="/calendar-blocks?property_id=1606&filter=past"', $body);
        // Sibling links preserve state: switching Property retains filter=past
        $this->assertStringContainsString('href="/calendar-blocks?property_id=1707&filter=past"', $body);
        $this->assertStringContainsString('href="/calendar-blocks?property_id=all&filter=past"', $body);
        // Sibling links preserve state: switching Status retains property_id=1606
        $this->assertStringContainsString('href="/calendar-blocks?property_id=1606&filter=upcoming"', $body);
        $this->assertStringContainsString('href="/calendar-blocks?property_id=1606&filter=all"', $body);

        // Filter pills have role="button"
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Apartment 1606\s*<\/a>/s', $body);
        $this->assertMatchesRegularExpression('/<a[^>]*role="button"[^>]*>\s*Concluded \(Past\)\s*<\/a>/s', $body);
    }

    public function testIndexRendersFullPageWhenHtmxTargetIsBody(): void
    {
        $session = ['csrf_token' => 'test_token', 'admin_user_id' => 1];
        $request = new Request(
            method: 'GET',
            uri: '/calendar-blocks',
            query: ['property_id' => '1606', 'filter' => 'upcoming'],
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_TARGET' => 'body',
            ]
        );
        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        // Must render full layout shell rather than partials
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringContainsString('<body', $body);
        $this->assertStringContainsString('Maintenance & Calendar Holds', $body);
        $this->assertStringContainsString('id="blocks-view-container"', $body);
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
        $this->assertSame('#modal-container', $response->getHeader('HX-Retarget'));
        $this->assertSame('innerHTML', $response->getHeader('HX-Reswap'));
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
        $this->assertSame('#modal-container', $response->getHeader('HX-Retarget'));
        $this->assertSame('innerHTML', $response->getHeader('HX-Reswap'));
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
        $this->assertSame('#modal-container', $response->getHeader('HX-Retarget'));
        $this->assertSame('innerHTML', $response->getHeader('HX-Reswap'));
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
        $body = $response->getBody();
        $this->assertStringContainsString('id="blocks-view-container"', $body);
        $this->assertStringContainsString('hx-swap-oob="outerHTML"', $body);
        $this->assertStringContainsString('id="calendar-blocks-header-actions"', $body);
        $this->assertStringContainsString('<div id="modal-container" hx-swap-oob="innerHTML"></div>', $body);
        $this->assertStringNotContainsString('<script>', $body);

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
        $this->assertSame('blockReleased', $response->getHeader('HX-Trigger'));
        $body = $response->getBody();
        $this->assertStringContainsString('id="blocks-view-container"', $body);
        $this->assertStringContainsString('id="calendar-blocks-header-actions" hx-swap-oob="outerHTML"', $body);

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

    public function testReleaseViaPostSucceedsAndReRendersContainers(): void
    {
        $futureStart = date('Y-m-d', strtotime('+2 days'));
        $futureEnd = date('Y-m-d', strtotime('+4 days'));
        $id = $this->blockRepo->createBlock('1707', $futureStart, $futureEnd, 'Deep clean AC', 1);

        $session = [
            'csrf_token' => 'valid_token',
            'admin_user_id' => 1,
            'admin_email' => 'admin@oceanviewflats.com',
        ];
        $request = new Request(
            method: 'POST',
            uri: "/calendar-blocks/{$id}/release",
            post: [
                'csrf_token' => 'valid_token',
                'property_id' => '1707',
                'filter' => 'upcoming',
            ],
            attributes: ['id' => $id],
            server: ['HTTP_HX_REQUEST' => 'true']
        );

        $response = $this->controller->delete($request, $session);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('blockReleased', $response->getHeader('HX-Trigger'));
        $body = $response->getBody();
        $this->assertStringContainsString('id="blocks-view-container"', $body);
        $this->assertStringContainsString('id="calendar-blocks-header-actions" hx-swap-oob="outerHTML"', $body);
        $this->assertStringContainsString('hx-get="/calendar-blocks/new?property_id=1707&filter=upcoming"', $body);

        $this->assertNull($this->blockRepo->findById($id));
    }
}

/**
 * Fake implementation of InboundChannelSyncServiceInterface for CalendarBlockController testing.
 */
final class FakeCalendarBlockSyncService implements \OceanViewFlats\Domain\Reservation\InboundChannelSyncServiceInterface
{
    /**
     * @param array<string|int, \OceanViewFlats\Domain\Reservation\ChannelSyncStatus> $statuses
     */
    public function __construct(
        public array $statuses = []
    ) {
    }

    public function sync(string $propertyId, bool $force = false, string $initiatedBy = 'system'): \OceanViewFlats\Domain\Reservation\ChannelSyncResult
    {
        $status = $this->statuses[$propertyId] ?? new \OceanViewFlats\Domain\Reservation\ChannelSyncStatus(
            propertyId: $propertyId,
            status: \OceanViewFlats\Domain\Reservation\ChannelSyncStatus::STATUS_HEALTHY,
            lastSyncedAt: date('c'),
            lastAttemptedAt: date('c'),
            httpCode: 200,
            blockedNightsCount: 0,
            errorMessage: null
        );

        return new \OceanViewFlats\Domain\Reservation\ChannelSyncResult(
            propertyId: $propertyId,
            status: $status,
            wasSkippedDueToCooldown: false,
            blockedNights: [],
            message: 'OK'
        );
    }

    public function syncAll(bool $force = false, string $initiatedBy = 'system'): array
    {
        return [
            '1606' => $this->sync('1606', $force, $initiatedBy),
            '1707' => $this->sync('1707', $force, $initiatedBy),
        ];
    }

    public function getStatus(string $propertyId): ?\OceanViewFlats\Domain\Reservation\ChannelSyncStatus
    {
        return $this->statuses[$propertyId] ?? null;
    }

    public function getAllStatuses(): array
    {
        return $this->statuses;
    }

    public function getFeedUrls(): array
    {
        return [
            '1606' => 'https://example.com/ical/1606.ics',
            '1707' => 'https://example.com/ical/1707.ics',
        ];
    }

    public function parseIcalToBlockedNights(string $icalContent): array
    {
        return [];
    }

    public function isCacheStale(string $propertyId, int $ttlSeconds = 900): bool
    {
        return false;
    }

    public function hasCacheFile(string $propertyId): bool
    {
        return isset($this->statuses[$propertyId]);
    }

    public function getCachedNights(string $propertyId): ?array
    {
        return isset($this->statuses[$propertyId]) ? ['2026-11-01', '2026-11-02'] : null;
    }
}
