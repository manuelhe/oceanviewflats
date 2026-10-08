<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Reservation;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\InMemoryReservationRepository;
use OceanViewFlats\Domain\Reservation\PdoReservationRepository;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReservationEntityEnrichmentTest extends TestCase
{
    private function createSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_uid TEXT UNIQUE,
            property_id TEXT,
            guest_name TEXT,
            guest_email TEXT,
            guest_phone TEXT,
            check_in TEXT,
            check_out TEXT,
            total_price REAL,
            refunded_amount REAL DEFAULT 0.00,
            source TEXT DEFAULT 'web',
            external_confirmation_code TEXT DEFAULT NULL,
            channel_block_uid TEXT DEFAULT NULL,
            status TEXT,
            payment_method_id TEXT,
            mercadopago_preference_id TEXT,
            mercadopago_payment_id TEXT,
            payment_status TEXT,
            payment_detail TEXT,
            lang TEXT,
            registry_completed INTEGER DEFAULT 0,
            registry_completed_at TEXT,
            door_code TEXT,
            notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        return $pdo;
    }

    public function testDefaultValuesForEnrichedFields(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_test_defaults',
            propertyId: '1606',
            guestName: 'Alex Morgan',
            guestEmail: 'alex@example.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1200000.0
        );

        $this->assertSame('web', $reservation->source);
        $this->assertNull($reservation->notes);
        $this->assertSame(0.0, $reservation->refundedAmount);
    }

    public function testExplicitValuesForEnrichedFields(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_test_custom',
            propertyId: '1707',
            guestName: 'Beatriz Gomez',
            guestEmail: 'beatriz@example.com',
            guestPhone: '+57 311 987 6543',
            checkIn: '2026-12-10',
            checkOut: '2026-12-15',
            totalPrice: 2500000.0,
            source: 'admin_manual',
            notes: 'Requires late check-out at 1 PM',
            refundedAmount: 500000.0
        );

        $this->assertSame('admin_manual', $reservation->source);
        $this->assertSame('Requires late check-out at 1 PM', $reservation->notes);
        $this->assertSame(500000.0, $reservation->refundedAmount);
    }

    public function testCreateFactoryMethod(): void
    {
        $reservation = Reservation::create(
            reservationUid: 'ovf_test_factory',
            propertyId: '1606',
            guestName: 'Carlos Slim',
            guestEmail: 'carlos@example.com',
            guestPhone: '+52 55 1234 5678',
            checkIn: '2026-12-20',
            checkOut: '2026-12-25',
            totalPrice: 3000000.0,
            status: ReservationStatus::CONFIRMED,
            source: 'corporate',
            notes: 'Executive booking',
            refundedAmount: 200000.0
        );

        $this->assertSame('ovf_test_factory', $reservation->reservationUid);
        $this->assertSame('1606', $reservation->propertyId);
        $this->assertSame(ReservationStatus::CONFIRMED, $reservation->status);
        $this->assertSame('corporate', $reservation->source);
        $this->assertSame('Executive booking', $reservation->notes);
        $this->assertSame(200000.0, $reservation->refundedAmount);
    }

    public function testFromArrayHydration(): void
    {
        $data = [
            'reservation_uid' => 'ovf_from_array',
            'property_id' => '1707',
            'guest_name' => 'Daniela Ospina',
            'guest_email' => 'daniela@example.com',
            'guest_phone' => '+57 315 222 3344',
            'check_in' => '2027-01-05',
            'check_out' => '2027-01-10',
            'total_price' => 1800000.0,
            'refunded_amount' => 150000.0,
            'source' => 'whatsapp',
            'status' => 'confirmed',
            'notes' => 'Early check-in approved',
            'registry_completed' => 1,
            'registry_completed_at' => '2027-01-02 12:00:00',
            'door_code' => '9876543#',
            'created_at' => '2027-01-01 08:30:00',
            'updated_at' => '2027-01-02 12:00:00',
        ];

        $reservation = Reservation::fromArray($data);

        $this->assertSame('ovf_from_array', $reservation->reservationUid);
        $this->assertSame('1707', $reservation->propertyId);
        $this->assertSame('Daniela Ospina', $reservation->guestName);
        $this->assertSame(1800000.0, $reservation->totalPrice);
        $this->assertSame(150000.0, $reservation->refundedAmount);
        $this->assertSame('whatsapp', $reservation->source);
        $this->assertSame('Early check-in approved', $reservation->notes);
        $this->assertSame(ReservationStatus::CONFIRMED, $reservation->status);
        $this->assertTrue($reservation->registryCompleted);
        $this->assertSame('9876543#', $reservation->doorCode);
        $this->assertSame('2027-01-01 08:30:00', $reservation->createdAt?->format('Y-m-d H:i:s'));
    }

    public function testToArraySerialization(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_to_array',
            propertyId: '1606',
            guestName: 'Elena Rostova',
            guestEmail: 'elena@example.com',
            guestPhone: '+7 999 123 4567',
            checkIn: '2027-02-01',
            checkOut: '2027-02-07',
            totalPrice: 2100000.0,
            status: ReservationStatus::CONFIRMED,
            source: 'ota_partner',
            notes: 'High floor preference',
            refundedAmount: 50000.0
        );

        $array = $reservation->toArray();

        $this->assertSame('ovf_to_array', $array['reservation_uid']);
        $this->assertSame('1606', $array['property_id']);
        $this->assertSame('ota_partner', $array['source']);
        $this->assertSame('High floor preference', $array['notes']);
        $this->assertSame(50000.0, $array['refunded_amount']);
        $this->assertSame('confirmed', $array['status']);
    }

    public function testWitherMethodsPreserveEnrichedFields(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_wither',
            propertyId: '1606',
            guestName: 'Fabio Cannavaro',
            guestEmail: 'fabio@example.com',
            guestPhone: '+39 06 1234567',
            checkIn: '2027-03-01',
            checkOut: '2027-03-06',
            totalPrice: 1900000.0,
            source: 'booking_engine',
            notes: 'Allergic to feathers',
            refundedAmount: 100000.0
        );

        // withStatus
        $withStatus = $reservation->withStatus(ReservationStatus::CONFIRMED, 'pay_123', 'approved');
        $this->assertSame('booking_engine', $withStatus->source);
        $this->assertSame('Allergic to feathers', $withStatus->notes);
        $this->assertSame(100000.0, $withStatus->refundedAmount);
        $this->assertSame(ReservationStatus::CONFIRMED, $withStatus->status);

        // withRegistryCompleted
        $completedTime = new DateTimeImmutable('2027-02-28 10:00:00');
        $withRegistry = $reservation->withRegistryCompleted($completedTime, '1234567#');
        $this->assertSame('booking_engine', $withRegistry->source);
        $this->assertSame('Allergic to feathers', $withRegistry->notes);
        $this->assertSame(100000.0, $withRegistry->refundedAmount);
        $this->assertTrue($withRegistry->registryCompleted);
        $this->assertSame('1234567#', $withRegistry->doorCode);

        // withDoorCode
        $withCode = $reservation->withDoorCode('7654321#');
        $this->assertSame('booking_engine', $withCode->source);
        $this->assertSame('Allergic to feathers', $withCode->notes);
        $this->assertSame(100000.0, $withCode->refundedAmount);
        $this->assertSame('7654321#', $withCode->doorCode);

        // withRefund
        $withRefund = $reservation->withRefund(50000.0, 'Partial cancellation fee waiver');
        $this->assertSame(150000.0, $withRefund->refundedAmount);
        $this->assertSame('Partial cancellation fee waiver', $withRefund->notes);
        $this->assertSame('booking_engine', $withRefund->source);

        // withNotes
        $withNotes = $reservation->withNotes('Updated notes');
        $this->assertSame('Updated notes', $withNotes->notes);
        $this->assertSame('booking_engine', $withNotes->source);
        $this->assertSame(100000.0, $withNotes->refundedAmount);
    }

    public function testPdoReservationRepositorySavesAndLoadsEnrichedFields(): void
    {
        $pdo = $this->createSqlitePdo();
        $repo = new PdoReservationRepository($pdo);

        $reservation = new Reservation(
            reservationUid: 'ovf_pdo_enriched',
            propertyId: '1606',
            guestName: 'Gabriel Garcia',
            guestEmail: 'gabriel@example.com',
            guestPhone: '+57 300 000 0000',
            checkIn: '2027-04-10',
            checkOut: '2027-04-15',
            totalPrice: 2200000.0,
            status: ReservationStatus::CONFIRMED,
            source: 'admin_dashboard',
            notes: 'Requires cot for toddler',
            refundedAmount: 250000.0,
            externalConfirmationCode: 'HM987654321',
            channelBlockUid: 'ical-uid-abc-123'
        );

        $saved = $repo->save($reservation);
        $this->assertSame('admin_dashboard', $saved->source);
        $this->assertSame('Requires cot for toddler', $saved->notes);
        $this->assertSame(250000.0, $saved->refundedAmount);
        $this->assertSame('HM987654321', $saved->externalConfirmationCode);
        $this->assertSame('ical-uid-abc-123', $saved->channelBlockUid);

        $fetched = $repo->findByUid('ovf_pdo_enriched');
        $this->assertNotNull($fetched);
        $this->assertSame('ovf_pdo_enriched', $fetched->reservationUid);
        $this->assertSame('admin_dashboard', $fetched->source);
        $this->assertSame('Requires cot for toddler', $fetched->notes);
        $this->assertSame(250000.0, $fetched->refundedAmount);
        $this->assertSame('HM987654321', $fetched->externalConfirmationCode);
        $this->assertSame('ical-uid-abc-123', $fetched->channelBlockUid);

        // Test update via save()
        $updated = $fetched->withRefund(50000.0, 'Updated: Toddler cot included + refund');
        $repo->save($updated);

        $refetched = $repo->findByUid('ovf_pdo_enriched');
        $this->assertNotNull($refetched);
        $this->assertSame(300000.0, $refetched->refundedAmount);
        $this->assertSame('Updated: Toddler cot included + refund', $refetched->notes);
        $this->assertSame('admin_dashboard', $refetched->source);
        $this->assertSame('HM987654321', $refetched->externalConfirmationCode);
        $this->assertSame('ical-uid-abc-123', $refetched->channelBlockUid);
    }

    public function testInMemoryReservationRepositorySavesAndLoadsEnrichedFields(): void
    {
        $repo = new InMemoryReservationRepository();

        $reservation = new Reservation(
            reservationUid: 'ovf_inmem_enriched',
            propertyId: '1707',
            guestName: 'Helena Moreno',
            guestEmail: 'helena@example.com',
            guestPhone: '+57 310 111 2233',
            checkIn: '2027-05-01',
            checkOut: '2027-05-05',
            totalPrice: 1750000.0,
            status: ReservationStatus::CONFIRMED,
            source: 'whatsapp_bot',
            notes: 'Late arrival after 9 PM',
            refundedAmount: 120000.0,
            externalConfirmationCode: 'AIRBNB-XYZ',
            channelBlockUid: 'block-uid-999'
        );

        $saved = $repo->save($reservation);
        $this->assertSame('whatsapp_bot', $saved->source);
        $this->assertSame('Late arrival after 9 PM', $saved->notes);
        $this->assertSame(120000.0, $saved->refundedAmount);
        $this->assertSame('AIRBNB-XYZ', $saved->externalConfirmationCode);
        $this->assertSame('block-uid-999', $saved->channelBlockUid);

        $fetched = $repo->findByUid('ovf_inmem_enriched');
        $this->assertNotNull($fetched);
        $this->assertSame('whatsapp_bot', $fetched->source);
        $this->assertSame('Late arrival after 9 PM', $fetched->notes);
        $this->assertSame(120000.0, $fetched->refundedAmount);
        $this->assertSame('AIRBNB-XYZ', $fetched->externalConfirmationCode);
        $this->assertSame('block-uid-999', $fetched->channelBlockUid);
    }

    public function testHasExternalOrPlaceholderEmail(): void
    {
        // 1. source === 'airbnb'
        $rAirbnbSource = new Reservation(
            reservationUid: 'ovf_abnb_source',
            propertyId: '1606',
            guestName: 'Airbnb Guest',
            guestEmail: 'realguest@gmail.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'airbnb'
        );
        $this->assertTrue($rAirbnbSource->hasExternalOrPlaceholderEmail());

        // 2. reservationUid starts with res-abnb-
        $rAirbnbUid = new Reservation(
            reservationUid: 'res-abnb-12345',
            propertyId: '1606',
            guestName: 'Airbnb UID Guest',
            guestEmail: 'realguest@gmail.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'external'
        );
        $this->assertTrue($rAirbnbUid->hasExternalOrPlaceholderEmail());

        // 3. email contains airbnb.com
        $rAirbnbDomain = new Reservation(
            reservationUid: 'ovf_abnb_domain',
            propertyId: '1606',
            guestName: 'Airbnb Domain Guest',
            guestEmail: 'automated-proxy@guest.airbnb.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'ota'
        );
        $this->assertTrue($rAirbnbDomain->hasExternalOrPlaceholderEmail());

        // 4. email is empty
        $rEmptyEmail = new Reservation(
            reservationUid: 'ovf_empty_email',
            propertyId: '1606',
            guestName: 'Empty Email Guest',
            guestEmail: '',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'direct'
        );
        $this->assertTrue($rEmptyEmail->hasExternalOrPlaceholderEmail());

        // 5. email starts with guest@
        $rPlaceholderGuest = new Reservation(
            reservationUid: 'ovf_placeholder_guest',
            propertyId: '1606',
            guestName: 'Placeholder Guest',
            guestEmail: 'guest@placeholder.org',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'manual'
        );
        $this->assertTrue($rPlaceholderGuest->hasExternalOrPlaceholderEmail());

        // 6. email starts with none@
        $rPlaceholderNone = new Reservation(
            reservationUid: 'ovf_placeholder_none',
            propertyId: '1606',
            guestName: 'None Guest',
            guestEmail: 'none@noemail.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'manual'
        );
        $this->assertTrue($rPlaceholderNone->hasExternalOrPlaceholderEmail());

        // 7. regular direct booking with valid personal email
        $rDirectNormal = new Reservation(
            reservationUid: 'ovf_direct_normal',
            propertyId: '1606',
            guestName: 'Direct Guest',
            guestEmail: 'direct.guest@gmail.com',
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0,
            source: 'web'
        );
        $this->assertFalse($rDirectNormal->hasExternalOrPlaceholderEmail());
    }

    public function testIsConcludedReturnsTrueForPastCheckout(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_past_checkout',
            propertyId: '1606',
            guestName: 'Past Guest',
            guestEmail: 'past@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2026-05-01',
            checkOut: '2026-05-05',
            totalPrice: 1000000.0
        );

        $nowAfterCheckout = new DateTimeImmutable('2026-05-06 00:00:00', new \DateTimeZone('America/Bogota'));
        $this->assertTrue($reservation->isConcluded($nowAfterCheckout));
    }

    public function testIsConcludedReturnsFalseForFutureCheckout(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_future_checkout',
            propertyId: '1606',
            guestName: 'Future Guest',
            guestEmail: 'future@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2026-12-01',
            checkOut: '2026-12-05',
            totalPrice: 1000000.0
        );

        $nowBeforeCheckout = new DateTimeImmutable('2026-12-03 14:00:00', new \DateTimeZone('America/Bogota'));
        $this->assertFalse($reservation->isConcluded($nowBeforeCheckout));
    }

    public function testIsConcludedBoundaryConditionOnCheckoutDate(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_boundary_checkout',
            propertyId: '1606',
            guestName: 'Boundary Guest',
            guestEmail: 'boundary@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            totalPrice: 1000000.0
        );

        $timezone = new \DateTimeZone('America/Bogota');

        // Exactly at 23:59:59 COT on checkout day -> still active (not concluded)
        $atThreshold = new DateTimeImmutable('2026-10-05 23:59:59', $timezone);
        $this->assertFalse($reservation->isConcluded($atThreshold));

        // 1 second after 23:59:59 COT (00:00:00 next day) -> concluded
        $afterThreshold = new DateTimeImmutable('2026-10-06 00:00:00', $timezone);
        $this->assertTrue($reservation->isConcluded($afterThreshold));
    }

    public function testIsConcludedWithDifferentTimezones(): void
    {
        $reservation = new Reservation(
            reservationUid: 'ovf_tz_checkout',
            propertyId: '1606',
            guestName: 'TZ Guest',
            guestEmail: 'tz@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            totalPrice: 1000000.0
        );

        // 2026-10-05 23:59:59 COT is 2026-10-06 04:59:59 UTC
        $utcBefore = new DateTimeImmutable('2026-10-06 04:59:59', new \DateTimeZone('UTC'));
        $this->assertFalse($reservation->isConcluded($utcBefore));

        // 2026-10-06 05:00:00 UTC is 2026-10-06 00:00:00 COT
        $utcAfter = new DateTimeImmutable('2026-10-06 05:00:01', new \DateTimeZone('UTC'));
        $this->assertTrue($reservation->isConcluded($utcAfter));
    }

    public function testIsConcludedDefaultReferenceTime(): void
    {
        $pastReservation = new Reservation(
            reservationUid: 'ovf_past_default',
            propertyId: '1606',
            guestName: 'Past Default Guest',
            guestEmail: 'pastdefault@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2020-01-01',
            checkOut: '2020-01-05',
            totalPrice: 1000000.0
        );
        $this->assertTrue($pastReservation->isConcluded());

        $futureReservation = new Reservation(
            reservationUid: 'ovf_future_default',
            propertyId: '1606',
            guestName: 'Future Default Guest',
            guestEmail: 'futuredefault@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2030-01-01',
            checkOut: '2030-01-05',
            totalPrice: 1000000.0
        );
        $this->assertFalse($futureReservation->isConcluded());
    }
}
