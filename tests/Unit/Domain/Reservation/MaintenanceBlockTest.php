<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Domain\Reservation\MaintenanceBlock;
use PHPUnit\Framework\TestCase;

final class MaintenanceBlockTest extends TestCase
{
    public function testConstructValidBlock(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Painting and AC repair',
            id: 1,
            createdBy: 42,
            createdAt: new DateTimeImmutable('2026-10-01 10:00:00')
        );

        $this->assertSame('1606', $block->propertyId);
        $this->assertSame('2026-10-10', $block->startDate);
        $this->assertSame('2026-10-15', $block->endDate);
        $this->assertSame('Painting and AC repair', $block->reason);
        $this->assertSame(1, $block->id);
        $this->assertSame(42, $block->createdBy);
        $this->assertNotNull($block->createdAt);
    }

    public function testConstructRejectsEmptyPropertyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Property ID cannot be empty');

        new MaintenanceBlock(
            propertyId: '',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Maintenance'
        );
    }

    public function testConstructRejectsEndBeforeOrEqualStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('End date (2026-10-10) must be after start date (2026-10-10)');

        new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-10',
            reason: 'Maintenance'
        );
    }

    public function testConstructRejectsEmptyReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason cannot be empty');

        new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: '   '
        );
    }

    public function testOverlapsHalfOpenIntervalSemantics(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Plumbing overhaul'
        );

        // Exact match
        $this->assertTrue($block->overlaps('2026-10-10', '2026-10-15'));

        // Guest checks in during block
        $this->assertTrue($block->overlaps('2026-10-12', '2026-10-18'));

        // Guest checks out during block
        $this->assertTrue($block->overlaps('2026-10-08', '2026-10-12'));

        // Guest encompasses block
        $this->assertTrue($block->overlaps('2026-10-05', '2026-10-20'));

        // Guest checks out on block start date -> NO overlap
        $this->assertFalse($block->overlaps('2026-10-05', '2026-10-10'));

        // Guest checks in on block end date -> NO overlap
        $this->assertFalse($block->overlaps('2026-10-15', '2026-10-20'));

        // Distant dates
        $this->assertFalse($block->overlaps('2026-11-01', '2026-11-05'));
    }

    public function testNightsArrayGeneration(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1707',
            startDate: '2026-10-10',
            endDate: '2026-10-13',
            reason: 'Deep cleaning'
        );

        $this->assertSame([
            '2026-10-10',
            '2026-10-11',
            '2026-10-12',
        ], $block->nights());
    }

    public function testIsConcluded(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1707',
            startDate: '2026-10-10',
            endDate: '2026-10-13',
            reason: 'Deep cleaning'
        );

        $nowActive = new DateTimeImmutable('2026-10-12');
        $this->assertFalse($block->isConcluded($nowActive));

        $nowCheckoutDay = new DateTimeImmutable('2026-10-13');
        $this->assertTrue($block->isConcluded($nowCheckoutDay));

        $nowFuture = new DateTimeImmutable('2026-10-15');
        $this->assertTrue($block->isConcluded($nowFuture));
    }

    public function testContainsDate(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Painting'
        );

        $this->assertFalse($block->containsDate('2026-10-09'));
        $this->assertTrue($block->containsDate('2026-10-10'));
        $this->assertTrue($block->containsDate('2026-10-12'));
        $this->assertTrue($block->containsDate('2026-10-14'));
        $this->assertFalse($block->containsDate('2026-10-15')); // Checkout day
        $this->assertFalse($block->containsDate('2026-10-16'));
    }

    public function testArrayAccessAndToArray(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-01 14:30:00');
        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'HVAC repair',
            id: 7,
            createdBy: 2,
            createdByName: 'Manuel Admin',
            createdAt: $createdAt
        );

        // Object properties
        $this->assertSame(7, $block->id);
        $this->assertSame('Manuel Admin', $block->createdByName);
        $this->assertSame('2026-10-01 14:30:00', $block->getCreatedAtString());

        // ArrayAccess
        $this->assertTrue(isset($block['id']));
        $this->assertSame(7, $block['id']);
        $this->assertSame('1606', $block['property_id']);
        $this->assertSame('2026-10-10', $block['start_date']);
        $this->assertSame('2026-10-15', $block['end_date']);
        $this->assertSame('HVAC repair', $block['reason']);
        $this->assertSame(2, $block['created_by']);
        $this->assertSame('Manuel Admin', $block['created_by_name']);
        $this->assertSame('2026-10-01 14:30:00', $block['created_at']);

        // toArray
        $array = $block->toArray();
        $this->assertSame(7, $array['id']);
        $this->assertSame('1606', $array['property_id']);
        $this->assertSame('HVAC repair', $array['reason']);
        $this->assertSame('Manuel Admin', $array['created_by_name']);
        $this->assertSame('2026-10-01 14:30:00', $array['created_at']);
    }

    public function testArrayAccessMutationThrows(): void
    {
        $block = new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-10-10',
            endDate: '2026-10-15',
            reason: 'Testing'
        );

        $this->expectException(\BadMethodCallException::class);
        $block['reason'] = 'Modified';
    }
}
