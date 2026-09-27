<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRenderer;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class ConfirmationEmailRendererTest extends TestCase
{
    private ConfirmationEmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ConfirmationEmailRenderer('https://www.oceanviewflats.com');
    }

    private function createReservation(
        string $lang = 'en',
        string $email = 'john@example.com'
    ): Reservation {
        return new Reservation(
            reservationUid: 'ovf_test_abc123',
            propertyId: '1606',
            guestName: 'John Doe',
            guestEmail: $email,
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-11-20',
            checkOut: '2026-11-23',
            totalPrice: 1500000.0,
            status: ReservationStatus::CONFIRMED,
            paymentMethodId: 'credit_card',
            mercadopagoPaymentId: 'pay_998877',
            paymentStatus: 'approved',
            lang: $lang,
            createdAt: new DateTimeImmutable('2026-09-27 10:00:00')
        );
    }

    public function testRendersGuestConfirmationHtmlWithStrictAdr0001Enforcement(): void
    {
        $reservation = $this->createReservation('en');
        $html = $this->renderer->renderGuestConfirmationHtml($reservation);

        // Strict ADR 0001 compliance: withhold door codes and guide URLs
        $this->assertStringNotContainsString('/guide/', $html);
        $this->assertStringNotContainsString('door code', strtolower($html));
        $this->assertStringNotContainsString('smart lock', strtolower($html));
        $this->assertStringNotContainsString('wi-fi', strtolower($html));
        $this->assertStringNotContainsString('wifi', strtolower($html));

        // Registry link and invitations must be present
        $expectedRegistryUrl = 'https://www.oceanviewflats.com/registry/?property=1606&check_in=2026-11-20&check_out=2026-11-23&code=ovf_test_abc123&lang=en';
        $this->assertStringContainsString($expectedRegistryUrl, $html);
        $this->assertStringContainsString('Complete Guest Registry', $html);
        $this->assertStringContainsString('Colombian statutory hospitality regulations', $html);
        $this->assertStringContainsString('ovf_test_abc123', $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('$ 1.500.000 COP', $html);
        $this->assertStringContainsString('3 nights', $html);
    }

    public function testRendersSpanishConfirmationEmail(): void
    {
        $reservation = $this->createReservation('es', 'carlos@example.com');
        $html = $this->renderer->renderGuestConfirmationHtml($reservation);

        $this->assertStringNotContainsString('/guide/', $html);
        $this->assertStringContainsString('https://www.oceanviewflats.com/registry/?property=1606&check_in=2026-11-20&check_out=2026-11-23&code=ovf_test_abc123&lang=es', $html);
        $this->assertStringContainsString('Completar Registro de Huéspedes', $html);
        $this->assertStringContainsString('normas legales de hotelería en Colombia', $html);
        $this->assertStringContainsString('3 noches', $html);

        $subject = $this->renderer->renderGuestSubject($reservation);
        $this->assertStringContainsString('¡Reserva CONFIRMADA!', $subject);
    }

    public function testRendersJapaneseConfirmationEmail(): void
    {
        $reservation = $this->createReservation('ja', 'kenji@example.jp');
        $html = $this->renderer->renderGuestConfirmationHtml($reservation);

        $this->assertStringNotContainsString('/guide/', $html);
        $this->assertStringContainsString('https://www.oceanviewflats.com/registry/?property=1606&check_in=2026-11-20&check_out=2026-11-23&code=ovf_test_abc123&lang=ja', $html);
        $this->assertStringContainsString('宿泊者名簿に登録する', $html);
        $this->assertStringContainsString('コロンビアの宿泊施設関連法規', $html);

        $subject = $this->renderer->renderGuestSubject($reservation);
        $this->assertStringContainsString('ご予約が確定しました！', $subject);
    }

    public function testEmailDomainFallbackToSpanishForLatamEmails(): void
    {
        // When lang is empty or invalid, falls back to Spanish for .co, .cl, .ar
        $reservation = $this->createReservation('', 'maria@example.com.co');
        $html = $this->renderer->renderGuestConfirmationHtml($reservation);

        $this->assertStringContainsString('lang=es', $html);
        $this->assertStringContainsString('Completar Registro de Huéspedes', $html);
    }

    public function testRendersHostNotificationHtml(): void
    {
        $reservation = $this->createReservation('en');
        $html = $this->renderer->renderHostNotificationHtml($reservation);

        $this->assertStringContainsString('Direct Booking Paid & Confirmed', $html);
        $this->assertStringContainsString('ovf_test_abc123', $html);
        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('+1 555 123 4567', $html);
        $this->assertStringContainsString('ADR 0001', $html);

        $subject = $this->renderer->renderHostSubject($reservation);
        $this->assertSame('DIRECT BOOKING PAID CONFIRMED: Prop 1606 (John Doe) - [EN]', $subject);
    }
}
