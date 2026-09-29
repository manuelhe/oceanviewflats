<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class CancellationEmailRendererTest extends TestCase
{
    private CancellationEmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new CancellationEmailRenderer('https://www.oceanviewflats.com');
    }

    private function createReservation(
        string $lang = 'en',
        string $email = 'guest@example.com'
    ): Reservation {
        return new Reservation(
            reservationUid: 'ovf_cancel_test_45',
            propertyId: '1606',
            guestName: 'Jane Smith',
            guestEmail: $email,
            guestPhone: '+57 300 123 4567',
            checkIn: '2026-11-20',
            checkOut: '2026-11-23',
            totalPrice: 1500000.0,
            status: ReservationStatus::CANCELLED,
            paymentMethodId: 'credit_card',
            mercadopagoPaymentId: 'pay_1234567',
            paymentStatus: 'refunded',
            lang: $lang,
            createdAt: new DateTimeImmutable('2026-09-27 10:00:00')
        );
    }

    public function testRendersFullRefundEnglishCancellationNotice(): void
    {
        $reservation = $this->createReservation('en');
        $internalReason = 'Internal staff note: guest emergency';
        $html = $this->renderer->renderGuestCancellationHtml(
            reservation: $reservation,
            refundAmount: 1500000.0,
            policyRetention: 0.0
        );

        // Verify key structural details
        $this->assertStringContainsString('Reservation Cancelled', $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('ovf_cancel_test_45', $html);
        $this->assertStringContainsString('2026-11-20', $html);
        $this->assertStringContainsString('2026-11-23', $html);
        $this->assertStringContainsString('3 nights', $html);
        $this->assertStringContainsString('$ 1.500.000 COP', $html);

        // Verify full refund note
        $this->assertStringContainsString('full refund of <strong>$ 1.500.000 COP</strong> has been issued', $html);
        $this->assertStringContainsString('5-10 business days', $html);

        // Verify support link
        $this->assertStringContainsString('https://www.oceanviewflats.com/contact', $html);

        // Invariant: internal operational reason must NEVER leak into guest email
        $this->assertStringNotContainsString($internalReason, $html);

        // Verify subject line
        $subject = $this->renderer->renderGuestSubject($reservation);
        $this->assertSame('Reservation Cancelled - OceanViewFlats 1606', $subject);
    }

    public function testRendersPartialRefundSpanishCancellationNotice(): void
    {
        $reservation = $this->createReservation('es', 'maria@example.com');
        $html = $this->renderer->renderGuestCancellationHtml(
            reservation: $reservation,
            refundAmount: 900000.0,
            policyRetention: 600000.0
        );

        $this->assertStringContainsString('Reserva Cancelada', $html);
        $this->assertStringContainsString('Estimado/a <strong>Jane Smith</strong>', $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('3 noches', $html);
        $this->assertStringContainsString('$ 1.500.000 COP', $html);
        $this->assertStringContainsString('$ 900.000 COP', $html);
        $this->assertStringContainsString('$ 600.000 COP', $html);

        // Partial refund text in Spanish
        $this->assertStringContainsString('reembolso parcial de <strong>$ 900.000 COP</strong>', $html);
        $this->assertStringContainsString('saldo restante de <strong>$ 600.000 COP</strong> ha sido retenido', $html);

        // Spanish support link
        $this->assertStringContainsString('https://www.oceanviewflats.com/contact/es.html', $html);

        $subject = $this->renderer->renderGuestSubject($reservation);
        $this->assertSame('Reserva Cancelada - OceanViewFlats 1606', $subject);
    }

    public function testRendersZeroRefundWithPolicyRetention(): void
    {
        $reservation = $this->createReservation('en');
        $html = $this->renderer->renderGuestCancellationHtml(
            reservation: $reservation,
            refundAmount: 0.0,
            policyRetention: 1500000.0
        );

        $this->assertStringContainsString('100% of the reservation total ($ 1.500.000 COP) has been retained', $html);
    }

    public function testRendersHostOperationalAlertWithInternalReason(): void
    {
        $reservation = $this->createReservation('es', 'diego@example.co');
        $staffReason = 'Host requested cancellation due to plumbing repairs';

        $html = $this->renderer->renderHostNotificationHtml(
            reservation: $reservation,
            refundAmount: 1500000.0,
            policyRetention: 0.0,
            reason: $staffReason
        );

        $this->assertStringContainsString('Reservation Cancelled', $html);
        $this->assertStringContainsString($staffReason, $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('ovf_cancel_test_45', $html);
        $this->assertStringContainsString('diego@example.co', $html);

        $subject = $this->renderer->renderHostSubject($reservation);
        $this->assertSame('[ADMIN] Reservation Cancelled: Prop 1606 (Jane Smith) - [ES]', $subject);
    }

    public function testDetectsLatamEmailDomainFallback(): void
    {
        // When lang is unspecified, .co email resolves to Spanish
        $reservation = $this->createReservation('', 'guest@domain.com.co');
        $subject = $this->renderer->renderGuestSubject($reservation);
        $this->assertSame('Reserva Cancelada - OceanViewFlats 1606', $subject);
    }

    public function testSupportsFrenchAndJapanese(): void
    {
        $resFr = $this->createReservation('fr');
        $htmlFr = $this->renderer->renderGuestCancellationHtml($resFr, 1500000.0, 0.0);
        $this->assertStringContainsString('Réservation Annulée', $htmlFr);
        $this->assertStringContainsString('https://www.oceanviewflats.com/contact/fr.html', $htmlFr);

        $resJa = $this->createReservation('ja');
        $htmlJa = $this->renderer->renderGuestCancellationHtml($resJa, 1500000.0, 0.0);
        $this->assertStringContainsString('ご予約がキャンセルされました', $htmlJa);
        $this->assertStringContainsString('https://www.oceanviewflats.com/contact/ja.html', $htmlJa);
    }
}
