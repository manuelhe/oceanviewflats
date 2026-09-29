<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Access;

use DateTimeImmutable;
use OceanViewFlats\Domain\Access\ConfigPropertyCredentialsProvider;
use OceanViewFlats\Domain\Access\GuideAccessService;
use OceanViewFlats\Domain\Reservation\InMemoryReservationRepository;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class GuideAccessServiceTest extends TestCase
{
    private InMemoryReservationRepository $repository;
    private ConfigPropertyCredentialsProvider $credentialsProvider;
    private GuideAccessService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReservationRepository();
        $this->credentialsProvider = new ConfigPropertyCredentialsProvider([
            '1707' => [
                'door_code' => '1707*',
                'wifi_ssid' => 'APTO1707_5G',
                'wifi_password' => 'Secret1707',
            ],
            '1606' => [
                'door_code' => '1606*',
                'wifi_ssid' => 'APTO1606_5G',
                'wifi_password' => 'Secret1606',
            ],
        ]);
        $this->service = new GuideAccessService(
            repository: $this->repository,
            credentialsProvider: $this->credentialsProvider,
            baseUrl: 'https://www.oceanviewflats.com'
        );
    }

    private function createReservation(
        string $uid = 'ovf_test_001',
        string $property = '1707',
        ReservationStatus $status = ReservationStatus::CONFIRMED,
        bool $registryCompleted = false
    ): Reservation {
        return new Reservation(
            reservationUid: $uid,
            propertyId: $property,
            guestName: 'Carlos Valderrama',
            guestEmail: 'carlos@example.com',
            guestPhone: '+57 300 111 2233',
            checkIn: '2026-11-10',
            checkOut: '2026-11-15',
            totalPrice: 2500000.0,
            status: $status,
            paymentMethodId: 'card',
            mercadopagoPaymentId: 'pay_999888',
            paymentStatus: 'approved',
            lang: 'es',
            createdAt: new DateTimeImmutable('2026-09-27 12:00:00'),
            registryCompleted: $registryCompleted,
            registryCompletedAt: $registryCompleted ? new DateTimeImmutable('2026-09-27 12:30:00') : null
        );
    }

    public function testVerifyAccessReturnsNotFoundForEmptyCode(): void
    {
        $result = $this->service->verifyAccess('   ');

        $this->assertFalse($result->verified);
        $this->assertSame('not_found', $result->status);
        $this->assertNull($result->credentials);
        $this->assertNull($result->reservation);
    }

    public function testVerifyAccessReturnsNotFoundForNonexistentReservation(): void
    {
        $result = $this->service->verifyAccess('ovf_nonexistent');

        $this->assertFalse($result->verified);
        $this->assertSame('not_found', $result->status);
        $this->assertNull($result->credentials);
    }

    public function testVerifyAccessReturnsUnauthorizedForPendingHold(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_pending_1',
            status: ReservationStatus::PENDING_PAYMENT,
            registryCompleted: false
        );
        $this->repository->save($reservation);

        $result = $this->service->verifyAccess('ovf_pending_1');

        $this->assertFalse($result->verified);
        $this->assertSame('unauthorized', $result->status);
        $this->assertNull($result->credentials);
        $this->assertStringContainsString('pending', strtolower($result->message));
    }

    public function testVerifyAccessReturnsUnauthorizedForCancelledReservation(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_cancelled_1',
            status: ReservationStatus::CANCELLED,
            registryCompleted: true
        );
        $this->repository->save($reservation);

        $result = $this->service->verifyAccess('ovf_cancelled_1');

        $this->assertFalse($result->verified);
        $this->assertSame('unauthorized', $result->status);
        $this->assertNull($result->credentials);
    }

    public function testVerifyAccessGatingWithholdsCredentialsWhenRegistryIncomplete(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_confirmed_noreg',
            property: '1707',
            status: ReservationStatus::CONFIRMED,
            registryCompleted: false
        );
        $this->repository->save($reservation);

        $result = $this->service->verifyAccess('ovf_confirmed_noreg', 'es');

        $this->assertFalse($result->verified);
        $this->assertSame('registry_required', $result->status);
        $this->assertSame('registry_required', $result->reason);
        $this->assertNull($result->credentials);
        $this->assertNotNull($result->registryUrl);

        // Verify registry URL contains necessary parameters
        $this->assertStringContainsString('/registry/?', $result->registryUrl);
        $this->assertStringContainsString('property=1707', $result->registryUrl);
        $this->assertStringContainsString('check_in=2026-11-10', $result->registryUrl);
        $this->assertStringContainsString('check_out=2026-11-15', $result->registryUrl);
        $this->assertStringContainsString('code=ovf_confirmed_noreg', $result->registryUrl);
        $this->assertStringContainsString('lang=es', $result->registryUrl);

        // Ensure serialized array NEVER leaks credentials
        $array = $result->toArray();
        $this->assertArrayNotHasKey('credentials', $array);
        $this->assertSame('registry_required', $array['status']);
        $this->assertSame($result->registryUrl, $array['registry_url']);
    }

    public function testVerifyAccessReleasesCredentialsWhenRegistryCompleted(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_confirmed_reg_done',
            property: '1707',
            status: ReservationStatus::CONFIRMED,
            registryCompleted: true
        );
        $this->repository->save($reservation);

        $result = $this->service->verifyAccess('ovf_confirmed_reg_done', 'en');

        $this->assertTrue($result->verified);
        $this->assertSame('verified', $result->status);
        $this->assertNull($result->reason);
        $this->assertNotNull($result->credentials);
        $this->assertSame('1707*', $result->credentials->doorCode);
        $this->assertSame('APTO1707_5G', $result->credentials->wifiSsid);
        $this->assertSame('Secret1707', $result->credentials->wifiPassword);

        // Verify serialized array includes credentials
        $array = $result->toArray();
        $this->assertTrue($array['verified']);
        $this->assertArrayHasKey('credentials', $array);
        $this->assertSame('1707*', $array['credentials']['door_code']);
        $this->assertSame('APTO1707_5G', $array['credentials']['wifi_ssid']);
        $this->assertSame('Secret1707', $array['credentials']['wifi_password']);
    }

    public function testMarkRegistryCompletedTransitionsReservationState(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_flow_transition',
            property: '1606',
            status: ReservationStatus::CONFIRMED,
            registryCompleted: false
        );
        $this->repository->save($reservation);

        // First verification: registry required
        $initial = $this->service->verifyAccess('ovf_flow_transition');
        $this->assertFalse($initial->verified);
        $this->assertSame('registry_required', $initial->status);

        // Mark registry completed in repository
        $updated = $this->repository->markRegistryCompleted('ovf_flow_transition');
        $this->assertNotNull($updated);
        $this->assertTrue($updated->registryCompleted);
        $this->assertNotNull($updated->registryCompletedAt);

        // Second verification: unlocked!
        $afterRegistry = $this->service->verifyAccess('ovf_flow_transition');
        $this->assertTrue($afterRegistry->verified);
        $this->assertSame('verified', $afterRegistry->status);
        $this->assertNotNull($afterRegistry->credentials);
        $this->assertSame('1606*', $afterRegistry->credentials->doorCode);
    }

    public function testVerifyAccessOverridesDoorCodeWithDynamicPinWhenPresent(): void
    {
        $reservation = $this->createReservation(
            uid: 'ovf_dynamic_pin_test',
            property: '1707',
            status: ReservationStatus::CONFIRMED,
            registryCompleted: false
        );
        $this->repository->save($reservation);

        // Complete with dynamic PIN
        $this->repository->markRegistryCompleted('ovf_dynamic_pin_test', null, '0654321#');

        $result = $this->service->verifyAccess('ovf_dynamic_pin_test');
        $this->assertTrue($result->verified);
        $this->assertSame('verified', $result->status);
        $this->assertNotNull($result->credentials);
        $this->assertSame('0654321#', $result->credentials->doorCode);
        $this->assertSame('APTO1707_5G', $result->credentials->wifiSsid);

        $array = $result->toArray();
        $this->assertSame('0654321#', $array['credentials']['door_code']);
    }
}
