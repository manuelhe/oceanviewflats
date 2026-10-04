<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Service;

use DateTimeImmutable;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use OceanViewFlats\Admin\Repository\AdminRateRepository;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Service\DashboardQueryService;
use OceanViewFlats\Domain\Quote\PropertyRatesConfig;
use OceanViewFlats\Domain\Reservation\ChannelBlock;
use OceanViewFlats\Domain\Reservation\ChannelSyncStatus;
use OceanViewFlats\Domain\Reservation\Dashboard\AlertSeverity;
use OceanViewFlats\Domain\Reservation\Dashboard\MovementType;
use OceanViewFlats\Domain\Reservation\InboundChannelSyncServiceInterface;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use PDO;
use PHPUnit\Framework\TestCase;

final class DashboardQueryServiceTest extends TestCase
{
    private PDO $pdo;
    private AdminReservationRepository $reservationRepo;
    private AdminCalendarBlockRepository $calendarBlockRepo;
    private AdminRateRepository $rateRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE
            );

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
                status TEXT NOT NULL DEFAULT "pending_payment",
                source TEXT NOT NULL DEFAULT "web",
                external_confirmation_code TEXT DEFAULT NULL,
                channel_block_uid TEXT DEFAULT NULL,
                registry_completed INTEGER NOT NULL DEFAULT 0,
                door_code TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE calendar_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                property_id TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                reason TEXT NOT NULL,
                created_by INTEGER DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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

            INSERT INTO admin_users (id, name, email) VALUES (1, "Manuel Admin", "admin@oceanviewflats.com");
        ');

        $this->reservationRepo = new AdminReservationRepository($this->pdo);
        $this->calendarBlockRepo = new AdminCalendarBlockRepository($this->pdo);
        $this->rateRepo = new AdminRateRepository($this->pdo, PropertyRatesConfig::createDefault());
    }

    public function testGetDashboardHubDataAssemblesAllMetrics(): void
    {
        $now = new DateTimeImmutable('2026-10-10');

        // 1. Seed reservations:
        // - In-house stay: 2026-10-08 to 2026-10-12 (Apt 1606, registry completed)
        // - Turnover on 2026-10-10 (Apt 1707): Guest A departs, Guest B checks in (missing registry)
        // - Future check-in on 2026-10-11 (Apt 1606, missing registry)
        $this->pdo->exec("
            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, source, registry_completed, door_code
            ) VALUES
            ('res-staying', '1606', 'Staying Guest', 'stay@test.com', '+573001', '2026-10-08', '2026-10-12', 100, 'confirmed', 'web', 1, '1111#'),
            ('res-dep-today', '1707', 'Departing Guest', 'dep@test.com', '+573002', '2026-10-05', '2026-10-10', 100, 'confirmed', 'web', 1, '2222#'),
            ('res-arr-today', '1707', 'Arriving Guest', 'arr@test.com', '+573003', '2026-10-10', '2026-10-14', 100, 'confirmed', 'web', 0, NULL),
            ('res-tomorrow', '1606', 'Tomorrow Guest', 'tomo@test.com', '+573004', '2026-10-11', '2026-10-15', 100, 'confirmed', 'airbnb', 0, NULL);
        ");

        // 2. Seed maintenance blocks:
        $this->calendarBlockRepo->createBlock('1606', '2026-10-13', '2026-10-16', 'AC Duct Cleaning', 1);

        // 3. Seed property rates:
        $this->rateRepo->createRate([
            'property_id' => '1707',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'season_name' => 'October High Tier',
            'price_per_night' => 550000.0,
            'min_stay' => 3,
        ]);

        // 4. Mock ChannelSyncService:
        $syncStatus1606 = new ChannelSyncStatus(
            propertyId: '1606',
            status: ChannelSyncStatus::STATUS_HEALTHY,
            lastSyncedAt: '2026-10-10T12:00:00+00:00',
            lastAttemptedAt: '2026-10-10T12:00:00+00:00',
            httpCode: 200,
            blockedNightsCount: 5
        );
        $syncService = $this->createMock(InboundChannelSyncServiceInterface::class);
        $syncService->method('getAllStatuses')->willReturn(['1606' => $syncStatus1606]);

        // 5. Mock Ledger with an un-onboarded channel block:
        $unonboardedBlock = new ChannelBlock('1707', '2026-10-12', '2026-10-15', 'airbnb', 'Airbnb (HM456)');
        $ledger = $this->createMock(ReservationLedgerInterface::class);
        $ledger->method('getChannelBlocks')->willReturnCallback(function (string $pid) use ($unonboardedBlock): array {
            return $pid === '1707' ? [$unonboardedBlock] : [];
        });

        $service = new DashboardQueryService(
            reservationRepo: $this->reservationRepo,
            calendarBlockRepo: $this->calendarBlockRepo,
            rateRepo: $this->rateRepo,
            channelSyncService: $syncService,
            ledger: $ledger
        );

        // Test Global "all" view
        $viewData = $service->getDashboardHubData('all', $now);

        // Assertions
        $this->assertSame('all', $viewData->selectedPropertyFilter);
        $this->assertSame(1, $viewData->todayArrivalsCount); // res-arr-today
        $this->assertSame(1, $viewData->todayDeparturesCount); // res-dep-today
        $this->assertSame(2, $viewData->activeStaysCount); // res-staying (1606) & res-arr-today (1707)

        // 7-day schedule keys
        $this->assertCount(7, $viewData->scheduleByDate);
        $this->assertArrayHasKey('2026-10-10', $viewData->scheduleByDate);
        $todayEvents = $viewData->scheduleByDate['2026-10-10'];
        $this->assertCount(2, $todayEvents);
        $this->assertSame(MovementType::CHECK_OUT, $todayEvents[0]->movementType);
        $this->assertSame(MovementType::TURNOVER, $todayEvents[1]->movementType);
        $this->assertSame('Departing Guest', $todayEvents[1]->departingGuestName);

        // Operational alerts
        // Alert 1: Arriving Guest check-in today without registry (CRITICAL)
        // Alert 2: Tomorrow Guest check-in tomorrow without registry (WARNING)
        // Alert 3: Un-onboarded Airbnb block starting 2026-10-12
        $this->assertNotEmpty($viewData->alerts);
        $this->assertSame(AlertSeverity::CRITICAL, $viewData->alerts[0]->severity);
        $this->assertSame('registry_res-arr-today', $viewData->alerts[0]->id);

        // Rate status
        $this->assertArrayHasKey('1707', $viewData->rateStatus);
        $this->assertSame(550000.0, $viewData->rateStatus['1707']->currentNightlyRate);
        $this->assertTrue($viewData->rateStatus['1707']->isSeasonalTierActive);
        $this->assertSame('October High Tier', $viewData->rateStatus['1707']->activeTierName);

        $this->assertArrayHasKey('1606', $viewData->rateStatus);
        $this->assertSame(350000.0, $viewData->rateStatus['1606']->currentNightlyRate); // baseline
        $this->assertFalse($viewData->rateStatus['1606']->isSeasonalTierActive);

        // Upcoming maintenance blocks
        $this->assertCount(1, $viewData->upcomingMaintenanceBlocks);
        $this->assertSame('AC Duct Cleaning', $viewData->upcomingMaintenanceBlocks[0]->reason);

        // Channel sync status
        $this->assertTrue($viewData->channelSyncData['is_healthy']);
        $this->assertArrayHasKey('1606', $viewData->channelSyncData['statuses']);

        // Test Property-Filtered "1606" view
        $viewData1606 = $service->getDashboardHubData('1606', $now);
        $this->assertSame('1606', $viewData1606->selectedPropertyFilter);
        $this->assertSame(0, $viewData1606->todayArrivalsCount);
        $this->assertSame(0, $viewData1606->todayDeparturesCount);
        $this->assertSame(1, $viewData1606->activeStaysCount);
        $this->assertCount(1, $viewData1606->rateStatus);
        $this->assertArrayHasKey('1606', $viewData1606->rateStatus);
    }
}
