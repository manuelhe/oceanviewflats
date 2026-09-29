<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Domain\Reservation\ChannelBlock;
use OceanViewFlats\Domain\Reservation\InMemoryChannelBlockSource;
use OceanViewFlats\Domain\Reservation\InMemoryMaintenanceBlockSource;
use OceanViewFlats\Domain\Reservation\InMemoryReservationRepository;
use OceanViewFlats\Domain\Reservation\MaintenanceBlock;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationConflictException;
use OceanViewFlats\Domain\Reservation\ReservationLedger;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class ReservationLedgerTest extends TestCase
{
    private InMemoryReservationRepository $repository;
    private InMemoryChannelBlockSource $channelBlockSource;
    private InMemoryMaintenanceBlockSource $maintenanceBlockSource;
    private ReservationLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryReservationRepository();
        $this->channelBlockSource = new InMemoryChannelBlockSource();
        $this->maintenanceBlockSource = new InMemoryMaintenanceBlockSource();
        $this->ledger = new ReservationLedger(
            repository: $this->repository,
            channelBlockSource: $this->channelBlockSource,
            maintenanceBlockSource: $this->maintenanceBlockSource
        );
    }

    public function testEmptyLedgerIsAvailable(): void
    {
        $this->assertTrue($this->ledger->isAvailable('1606', '2026-07-01', '2026-07-05'));
        $this->assertEmpty($this->ledger->getConflictReasons('1606', '2026-07-01', '2026-07-05'));
        $this->assertEmpty($this->ledger->getBlockedNights('1606'));
    }

    public function testInvertedDatesAreNotAvailable(): void
    {
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-07-05', '2026-07-01'));
        $reasons = $this->ledger->getConflictReasons('1606', '2026-07-05', '2026-07-01');
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('must be after check-in date', $reasons[0]);
    }

    public function testEphemeralChannelBlocksBlockAvailabilityWithoutWritingToDatabase(): void
    {
        // Add ephemeral channel block (ADR 0002)
        $this->channelBlockSource->addBlock(new ChannelBlock(
            propertyId: '1606',
            startDate: '2026-07-10',
            endDate: '2026-07-15',
            source: 'airbnb'
        ));

        // Overlapping range is blocked
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-07-12', '2026-07-14'));
        $reasons = $this->ledger->getConflictReasons('1606', '2026-07-12', '2026-07-14');
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('external airbnb channel block', $reasons[0]);

        // Adjacent stays are available (check-in on checkout date)
        $this->assertTrue($this->ledger->isAvailable('1606', '2026-07-07', '2026-07-10'));
        $this->assertTrue($this->ledger->isAvailable('1606', '2026-07-15', '2026-07-18'));

        // Different property is unaffected
        $this->assertTrue($this->ledger->isAvailable('1707', '2026-07-10', '2026-07-15'));

        // Crucial ADR 0002 invariant: Channel blocks are NEVER written to the reservations repository
        $this->assertCount(0, $this->repository->all());
    }

    public function testHoldCreationPersistsPendingReservationAndBlocksDates(): void
    {
        $now = new DateTimeImmutable('2026-07-01 10:00:00');

        $reservation = new Reservation(
            reservationUid: 'ovf_test_1',
            propertyId: '1606',
            guestName: 'Ana Gomez',
            guestEmail: 'ana@example.com',
            guestPhone: '+573001234567',
            checkIn: '2026-07-10',
            checkOut: '2026-07-13',
            totalPrice: 1150000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'card',
            createdAt: $now
        );

        $saved = $this->ledger->hold($reservation, $now);
        $this->assertSame('ovf_test_1', $saved->reservationUid);
        $this->assertCount(1, $this->repository->all());

        // Now dates are occupied
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-07-11', '2026-07-14', $now));

        // Attempting a second hold on overlapping dates throws ReservationConflictException
        $conflictingReservation = new Reservation(
            reservationUid: 'ovf_test_2',
            propertyId: '1606',
            guestName: 'Carlos Perez',
            guestEmail: 'carlos@example.com',
            guestPhone: '+573119876543',
            checkIn: '2026-07-12',
            checkOut: '2026-07-15',
            totalPrice: 1150000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'card',
            createdAt: $now
        );

        $this->expectException(ReservationConflictException::class);
        $this->expectExceptionMessage('Calendar conflict for property 1606 from 2026-07-12 to 2026-07-15');
        $this->ledger->hold($conflictingReservation, $now);
    }

    public function testStandardHoldExpiresAfter30MinutesForCardPayments(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-01 10:00:00');

        $reservation = new Reservation(
            reservationUid: 'ovf_card_hold',
            propertyId: '1606',
            guestName: 'Beatriz Silva',
            guestEmail: 'beatriz@example.com',
            guestPhone: '+573001112233',
            checkIn: '2026-07-10',
            checkOut: '2026-07-13',
            totalPrice: 1150000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'card',
            createdAt: $createdAt
        );

        $this->ledger->hold($reservation, $createdAt);

        // At T = 29 minutes: hold is active
        $t29 = new DateTimeImmutable('2026-07-01 10:29:00');
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-07-10', '2026-07-13', $t29));

        // At T = 31 minutes: standard hold has expired automatically!
        $t31 = new DateTimeImmutable('2026-07-01 10:31:00');
        $this->assertTrue($this->ledger->isAvailable('1606', '2026-07-10', '2026-07-13', $t31));

        // Another guest can now hold those dates
        $newReservation = new Reservation(
            reservationUid: 'ovf_new_guest',
            propertyId: '1606',
            guestName: 'David Lopez',
            guestEmail: 'david@example.com',
            guestPhone: '+573204445566',
            checkIn: '2026-07-10',
            checkOut: '2026-07-13',
            totalPrice: 1150000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'card',
            createdAt: $t31
        );

        $held = $this->ledger->hold($newReservation, $t31);
        $this->assertSame('ovf_new_guest', $held->reservationUid);
    }

    public function testExtendedVoucherHoldPreservesDatesFor72HoursPerAdr0003(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-01 10:00:00');

        $reservation = new Reservation(
            reservationUid: 'ovf_efecty_hold',
            propertyId: '1707',
            guestName: 'Elena Morales',
            guestEmail: 'elena@example.com',
            guestPhone: '+573155556677',
            checkIn: '2026-07-20',
            checkOut: '2026-07-25',
            totalPrice: 2470000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'efecty', // Offline Cash Voucher
            createdAt: $createdAt
        );

        $this->ledger->hold($reservation, $createdAt);

        // At T = 31 minutes: still holding! (unlike card payments)
        $t31m = new DateTimeImmutable('2026-07-01 10:31:00');
        $this->assertFalse($this->ledger->isAvailable('1707', '2026-07-20', '2026-07-25', $t31m));

        // At T = 24 hours: still holding!
        $t24h = new DateTimeImmutable('2026-07-02 10:00:00');
        $this->assertFalse($this->ledger->isAvailable('1707', '2026-07-20', '2026-07-25', $t24h));

        // At T = 71 hours: still holding!
        $t71h = new DateTimeImmutable('2026-07-04 09:00:00');
        $this->assertFalse($this->ledger->isAvailable('1707', '2026-07-20', '2026-07-25', $t71h));

        // At T = 73 hours: expired!
        $t73h = new DateTimeImmutable('2026-07-04 11:00:00');
        $this->assertTrue($this->ledger->isAvailable('1707', '2026-07-20', '2026-07-25', $t73h));
    }

    public function testConfirmTransitionsStateAndHoldsIndefinitely(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-01 10:00:00');

        $reservation = new Reservation(
            reservationUid: 'ovf_settled',
            propertyId: '1606',
            guestName: 'Fernando Ruiz',
            guestEmail: 'fernando@example.com',
            guestPhone: '+573187778899',
            checkIn: '2026-08-01',
            checkOut: '2026-08-05',
            totalPrice: 1500000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            paymentMethodId: 'card',
            createdAt: $createdAt
        );

        $this->ledger->hold($reservation, $createdAt);

        // Confirm reservation
        $confirmed = $this->ledger->confirm(
            reservationUid: 'ovf_settled',
            paymentId: 'mp_pay_998877',
            paymentStatus: 'approved',
            paymentDetail: 'accredited'
        );

        $this->assertTrue($confirmed->status->isConfirmed());
        $this->assertSame('mp_pay_998877', $confirmed->mercadopagoPaymentId);
        $this->assertSame('approved', $confirmed->paymentStatus);

        // Even 30 days later, confirmed reservation holds indefinitely
        $t30d = new DateTimeImmutable('2026-07-31 10:00:00');
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-08-01', '2026-08-05', $t30d));
    }

    public function testCancelReleasesDatesBackToAvailability(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-01 10:00:00');

        $reservation = new Reservation(
            reservationUid: 'ovf_to_cancel',
            propertyId: '1606',
            guestName: 'Gabriela Ortiz',
            guestEmail: 'gabriela@example.com',
            guestPhone: '+573123334455',
            checkIn: '2026-08-10',
            checkOut: '2026-08-15',
            totalPrice: 1850000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            createdAt: $createdAt
        );

        $this->ledger->hold($reservation, $createdAt);
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-08-10', '2026-08-15', $createdAt));

        $cancelled = $this->ledger->cancel('ovf_to_cancel', 'Guest request');
        $this->assertTrue($cancelled->status->isCancelled());

        // Immediately available again
        $this->assertTrue($this->ledger->isAvailable('1606', '2026-08-10', '2026-08-15', $createdAt));
    }

    public function testGetBlockedNightsMergesChannelBlocksAndActiveReservations(): void
    {
        $now = new DateTimeImmutable('2026-07-01 10:00:00');

        // Ephemeral channel block: 2026-07-05 to 2026-07-07 (nights 07-05, 07-06)
        $this->channelBlockSource->addBlock(new ChannelBlock(
            propertyId: '1606',
            startDate: '2026-07-05',
            endDate: '2026-07-07',
            source: 'airbnb'
        ));

        // Active direct reservation: 2026-07-08 to 2026-07-10 (nights 07-08, 07-09)
        $this->ledger->hold(new Reservation(
            reservationUid: 'ovf_merged',
            propertyId: '1606',
            guestName: 'Hugo Boss',
            guestEmail: 'hugo@example.com',
            guestPhone: '+573101234567',
            checkIn: '2026-07-08',
            checkOut: '2026-07-10',
            totalPrice: 780000.0,
            status: ReservationStatus::CONFIRMED,
            createdAt: $now
        ), $now);

        $blockedNights = $this->ledger->getBlockedNights('1606', $now);

        $this->assertSame([
            '2026-07-05',
            '2026-07-06',
            '2026-07-08',
            '2026-07-09',
        ], $blockedNights);
    }

    public function testMaintenanceBlocksBlockAvailabilityAndPreventHolds(): void
    {
        $now = new DateTimeImmutable('2026-08-01 10:00:00');

        $this->maintenanceBlockSource->addBlock(new MaintenanceBlock(
            propertyId: '1606',
            startDate: '2026-08-10',
            endDate: '2026-08-14',
            reason: 'Painting and AC repair',
            id: 10
        ));

        // Overlapping range is blocked
        $this->assertFalse($this->ledger->isAvailable('1606', '2026-08-11', '2026-08-13'));
        $reasons = $this->ledger->getConflictReasons('1606', '2026-08-11', '2026-08-13');
        $this->assertCount(1, $reasons);
        $this->assertStringContainsString('Dates overlap maintenance hold (Painting and AC repair: 2026-08-10 to 2026-08-14)', $reasons[0]);

        // findMaintenanceConflict identifies the block
        $conflict = $this->ledger->findMaintenanceConflict('1606', '2026-08-12', '2026-08-15');
        $this->assertNotNull($conflict);
        $this->assertSame('Painting and AC repair', $conflict->reason);

        // Attempting to hold overlapping dates throws ReservationConflictException
        $this->expectException(ReservationConflictException::class);
        $this->expectExceptionMessage('Dates overlap maintenance hold (Painting and AC repair: 2026-08-10 to 2026-08-14)');

        $this->ledger->hold(new Reservation(
            reservationUid: 'ovf_blocked_by_maintenance',
            propertyId: '1606',
            guestName: 'Blocked Guest',
            guestEmail: 'guest@example.com',
            guestPhone: '+573100000000',
            checkIn: '2026-08-12',
            checkOut: '2026-08-16',
            totalPrice: 500000.0,
            status: ReservationStatus::PENDING_PAYMENT,
            createdAt: $now
        ), $now);
    }

    public function testGetBlockedNightsIncludesMaintenanceNights(): void
    {
        $now = new DateTimeImmutable('2026-08-01 10:00:00');

        $this->maintenanceBlockSource->addBlock(new MaintenanceBlock(
            propertyId: '1707',
            startDate: '2026-08-05',
            endDate: '2026-08-08',
            reason: 'Flooring'
        ));

        $nights = $this->ledger->getBlockedNights('1707', $now);
        $this->assertSame(['2026-08-05', '2026-08-06', '2026-08-07'], $nights);
    }
}
