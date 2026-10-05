<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation\Dashboard;

use OceanViewFlats\Domain\Reservation\Dashboard\AlertSeverity;
use OceanViewFlats\Domain\Reservation\Dashboard\AlertType;
use OceanViewFlats\Domain\Reservation\Dashboard\DashboardHubViewData;
use OceanViewFlats\Domain\Reservation\Dashboard\MovementType;
use OceanViewFlats\Domain\Reservation\Dashboard\OperationalAlert;
use OceanViewFlats\Domain\Reservation\Dashboard\OperationsEvent;
use OceanViewFlats\Domain\Reservation\Dashboard\PropertyRateStatus;
use OceanViewFlats\Domain\Reservation\MaintenanceBlock;
use PHPUnit\Framework\TestCase;

final class DashboardModelTest extends TestCase
{
    public function testEnumsHaveExpectedValues(): void
    {
        $this->assertSame('critical', AlertSeverity::CRITICAL->value);
        $this->assertSame('warning', AlertSeverity::WARNING->value);
        $this->assertSame('info', AlertSeverity::INFO->value);

        $this->assertSame('incomplete_guest_registry', AlertType::INCOMPLETE_GUEST_REGISTRY->value);
        $this->assertSame('unonboarded_channel_block', AlertType::UNONBOARDED_CHANNEL_BLOCK->value);

        $this->assertSame('check_in', MovementType::CHECK_IN->value);
        $this->assertSame('check_out', MovementType::CHECK_OUT->value);
        $this->assertSame('turnover', MovementType::TURNOVER->value);
    }

    public function testOperationalAlertInstantiationAndAttributes(): void
    {
        $alert = new OperationalAlert(
            id: 'alert-1',
            type: AlertType::INCOMPLETE_GUEST_REGISTRY,
            severity: AlertSeverity::CRITICAL,
            propertyId: '1606',
            title: 'Critical Guest Alert',
            description: 'Registry missing for today arrival',
            dueDate: '2026-10-10',
            reservationUid: 'res-abc-123',
            guestName: 'Jane Doe',
            channelBlockUid: null,
            source: 'web',
            actionPayload: ['custom_key' => 'custom_val']
        );

        $this->assertSame('alert-1', $alert->id);
        $this->assertSame(AlertType::INCOMPLETE_GUEST_REGISTRY, $alert->type);
        $this->assertSame(AlertSeverity::CRITICAL, $alert->severity);
        $this->assertSame('1606', $alert->propertyId);
        $this->assertSame('Critical Guest Alert', $alert->title);
        $this->assertSame('Registry missing for today arrival', $alert->description);
        $this->assertSame('2026-10-10', $alert->dueDate);
        $this->assertSame('res-abc-123', $alert->reservationUid);
        $this->assertSame('Jane Doe', $alert->guestName);
        $this->assertNull($alert->channelBlockUid);
        $this->assertSame('web', $alert->source);
        $this->assertSame(['custom_key' => 'custom_val'], $alert->actionPayload);

        $array = $alert->toArray();
        $this->assertSame('alert-1', $array['id']);
        $this->assertSame('critical', $array['severity']);
        $this->assertSame('incomplete_guest_registry', $array['type']);
    }

    public function testOperationsEventTurnoverProperties(): void
    {
        $event = new OperationsEvent(
            date: '2026-10-10',
            movementType: MovementType::TURNOVER,
            propertyId: '1606',
            reservationUid: 'res-arriving-456',
            guestName: 'Arriving Guest',
            guestPhone: '+573001234567',
            status: 'confirmed',
            registryCompleted: false,
            doorCode: null,
            source: 'airbnb',
            externalConfirmationCode: 'HM-12345',
            departingReservationUid: 'res-departing-123',
            departingGuestName: 'Departing Guest'
        );

        $this->assertSame('2026-10-10', $event->date);
        $this->assertSame(MovementType::TURNOVER, $event->movementType);
        $this->assertSame('1606', $event->propertyId);
        $this->assertSame('res-arriving-456', $event->reservationUid);
        $this->assertSame('Arriving Guest', $event->guestName);
        $this->assertSame('+573001234567', $event->guestPhone);
        $this->assertSame('confirmed', $event->status);
        $this->assertFalse($event->registryCompleted);
        $this->assertNull($event->doorCode);
        $this->assertSame('airbnb', $event->source);
        $this->assertSame('HM-12345', $event->externalConfirmationCode);
        $this->assertSame('res-departing-123', $event->departingReservationUid);
        $this->assertSame('Departing Guest', $event->departingGuestName);

        $array = $event->toArray();
        $this->assertSame('turnover', $array['movement_type']);
        $this->assertSame('Departing Guest', $array['departing_guest_name']);
    }

    public function testPropertyRateStatusBaselineAndSeasonal(): void
    {
        $baseline = new PropertyRateStatus(
            propertyId: '1606',
            currentNightlyRate: 350000.0,
            isSeasonalTierActive: false,
            activeTierName: null,
            activeTierEndDate: null,
            nextTierRate: 500000.0,
            nextTierName: 'High Season',
            nextTierStartDate: '2026-12-01'
        );

        $this->assertSame('1606', $baseline->propertyId);
        $this->assertSame(350000.0, $baseline->currentNightlyRate);
        $this->assertFalse($baseline->isSeasonalTierActive);
        $this->assertNull($baseline->activeTierName);
        $this->assertNull($baseline->activeTierEndDate);
        $this->assertSame('High Season', $baseline->nextTierName);
        $this->assertSame('2026-12-01', $baseline->nextTierStartDate);
        $this->assertSame(500000.0, $baseline->nextTierRate);

        $seasonal = new PropertyRateStatus(
            propertyId: '1707',
            currentNightlyRate: 600000.0,
            isSeasonalTierActive: true,
            activeTierName: 'Festive Tier',
            activeTierEndDate: '2026-10-20',
            nextTierRate: null,
            nextTierName: null,
            nextTierStartDate: null
        );

        $this->assertSame('1707', $seasonal->propertyId);
        $this->assertSame(600000.0, $seasonal->currentNightlyRate);
        $this->assertTrue($seasonal->isSeasonalTierActive);
        $this->assertSame('Festive Tier', $seasonal->activeTierName);
        $this->assertSame('2026-10-20', $seasonal->activeTierEndDate);

        $array = $seasonal->toArray();
        $this->assertSame('1707', $array['property_id']);
        $this->assertTrue($array['is_seasonal_tier_active']);
    }

    public function testDashboardHubViewDataAggregation(): void
    {
        $alert = new OperationalAlert(
            id: 'a1',
            type: AlertType::INCOMPLETE_GUEST_REGISTRY,
            severity: AlertSeverity::WARNING,
            propertyId: '1606',
            title: 'Warning Alert',
            description: 'Check-in tomorrow',
            dueDate: '2026-10-11'
        );

        $event = new OperationsEvent(
            date: '2026-10-10',
            movementType: MovementType::CHECK_IN,
            propertyId: '1606',
            reservationUid: 'res-1',
            guestName: 'Guest One',
            guestPhone: null,
            status: 'confirmed',
            registryCompleted: true,
            doorCode: '1234#',
            source: 'web'
        );

        $rateStatus = new PropertyRateStatus(
            propertyId: '1606',
            currentNightlyRate: 350000.0,
            isSeasonalTierActive: false,
            activeTierName: null,
            activeTierEndDate: null
        );

        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-15',
            endDate: '2026-10-17',
            reason: 'Painting',
            id: 1,
            createdBy: 1,
            createdByName: 'Manuel Admin'
        );

        $hubData = new DashboardHubViewData(
            selectedPropertyFilter: '1606',
            alerts: [$alert],
            scheduleByDate: ['2026-10-10' => [$event]],
            rateStatus: ['1606' => $rateStatus],
            upcomingMaintenanceBlocks: [$block],
            channelSyncData: ['is_healthy' => true],
            todayArrivalsCount: 1,
            todayDeparturesCount: 0,
            activeStaysCount: 1
        );

        $this->assertSame('1606', $hubData->selectedPropertyFilter);
        $this->assertCount(1, $hubData->alerts);
        $this->assertCount(1, $hubData->scheduleByDate);
        $this->assertCount(1, $hubData->rateStatus);
        $this->assertCount(1, $hubData->upcomingMaintenanceBlocks);
        $this->assertTrue($hubData->channelSyncData['is_healthy']);
        $this->assertSame(1, $hubData->todayArrivalsCount);
        $this->assertSame(0, $hubData->todayDeparturesCount);
        $this->assertSame(1, $hubData->activeStaysCount);
    }
}
