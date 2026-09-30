<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Integration\Fulfillment;

use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRenderer;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class CpanelLayoutEmailRendererTest extends TestCase
{
    public function testRenderersInstantiateAndRenderWithoutError(): void
    {
        $confirmationRenderer = new ConfirmationEmailRenderer();
        $cancellationRenderer = new CancellationEmailRenderer();

        $reservation = new Reservation(
            id: 1,
            reservationUid: 'OVF-TEST-CPANEL',
            propertyId: '1606',
            checkIn: '2026-11-01',
            checkOut: '2026-11-05',
            guestName: 'Maria Santos',
            guestEmail: 'maria@example.com',
            guestPhone: '+573001234567',
            totalPrice: 1200000,
            status: ReservationStatus::CONFIRMED,
            lang: 'es'
        );

        $confirmHtml = $confirmationRenderer->renderGuestConfirmationHtml($reservation);
        $this->assertStringContainsString('OVF-TEST-CPANEL', $confirmHtml);
        $this->assertStringContainsString('Maria Santos', $confirmHtml);

        $cancelHtml = $cancellationRenderer->renderGuestCancellationHtml($reservation, 1200000, 0);
        $this->assertStringContainsString('OVF-TEST-CPANEL', $cancelHtml);
    }
}
