<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Fulfillment\AccessDispatchEmailRenderer;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\TestCase;

final class AccessDispatchEmailRendererTest extends TestCase
{
    private AccessDispatchEmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AccessDispatchEmailRenderer('https://www.oceanviewflats.com');
    }

    private function createReservation(
        string $lang = 'en',
        string $email = 'jane@example.com',
        string $name = 'Jane Smith'
    ): Reservation {
        return new Reservation(
            reservationUid: 'ovf_dispatch_131',
            propertyId: '1606',
            guestName: $name,
            guestEmail: $email,
            guestPhone: '+1 555 123 4567',
            checkIn: '2026-11-20',
            checkOut: '2026-11-23',
            totalPrice: 1500000.0,
            status: ReservationStatus::CONFIRMED,
            paymentMethodId: 'credit_card',
            mercadopagoPaymentId: 'pay_1234567',
            paymentStatus: 'approved',
            lang: $lang,
            createdAt: new DateTimeImmutable('2026-09-27 10:00:00')
        );
    }

    public function testRendersEnglishAccessDispatchNotice(): void
    {
        $reservation = $this->createReservation('en');
        $doorCode = '7891234#';

        // 1. Subject line
        $subject = $this->renderer->renderSubject($reservation);
        $this->assertSame('Access Credentials & Arrival Guide - OceanViewFlats 1606', $subject);

        // 2. Guide URL
        $guideUrl = $this->renderer->buildGuideUrl($reservation, 'en');
        $this->assertSame('https://www.oceanviewflats.com/guide/?code=ovf_dispatch_131&lang=en', $guideUrl);

        // 3. Plain Text Rendering
        $plain = $this->renderer->renderPlainText(
            reservation: $reservation,
            doorCode: $doorCode,
            guideUrl: $guideUrl,
            lang: 'en'
        );

        $this->assertStringContainsString('OceanViewFlats - Your Access Credentials & Arrival Guide', $plain);
        $this->assertStringContainsString('Jane Smith', $plain);
        $this->assertStringContainsString('OceanViewFlats 1606', $plain);
        $this->assertStringContainsString('ovf_dispatch_131', $plain);
        $this->assertStringContainsString('2026-11-20', $plain);
        $this->assertStringContainsString('2026-11-23', $plain);
        $this->assertStringContainsString('DOOR PIN: 7891234#', $plain);
        $this->assertStringContainsString('Enter this 7-digit PIN followed by the # key', $plain);
        $this->assertStringContainsString($guideUrl, $plain);
        $this->assertStringContainsString('Quiet hours: 10:00 PM – 8:00 AM', $plain);
        $this->assertStringContainsString('Smoking is strictly prohibited', $plain);

        // 4. HTML Rendering
        $html = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            guideUrl: $guideUrl,
            lang: 'en'
        );

        $this->assertStringContainsString('Your Access Credentials & Arrival Guide', $html);
        $this->assertStringContainsString('Jane Smith', $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('ovf_dispatch_131', $html);
        $this->assertStringContainsString('2026-11-20', $html);
        $this->assertStringContainsString('2026-11-23', $html);
        $this->assertStringContainsString('7891234#', $html);
        $this->assertStringContainsString('Enter this 7-digit PIN followed by the # key', $html);
        $this->assertStringContainsString(htmlspecialchars($guideUrl, ENT_QUOTES, 'UTF-8'), $html);
        $this->assertStringContainsString('Open Guest Guide', $html);
        $this->assertStringContainsString('Quiet hours: 10:00 PM – 8:00 AM', $html);
        $this->assertStringContainsString('Smoking is strictly prohibited', $html);
        $this->assertStringContainsString('Present physical IDs at the reception desk', $html);
    }

    public function testRendersAllSupportedLanguages(): void
    {
        $reservation = $this->createReservation();
        $doorCode = '3456789#';

        $expectations = [
            'en' => [
                'subject' => 'Access Credentials & Arrival Guide - OceanViewFlats 1606',
                'title' => 'Your Access Credentials & Arrival Guide',
                'pin_label' => 'Smart Lock Door PIN',
                'btn' => 'Open Guest Guide',
                'parking_label' => 'Assigned Parking',
            ],
            'es' => [
                'subject' => 'Credenciales de Acceso y Guía de Llegada - OceanViewFlats 1606',
                'title' => 'Tus Credenciales de Acceso y Guía de Llegada',
                'pin_label' => 'PIN de Acceso Smart Lock',
                'btn' => 'Abrir Guía del Huésped',
                'parking_label' => 'Parqueadero Asignado',
            ],
            'fr' => [
                'subject' => 'Identifiants d’accès et guide d’arrivée - OceanViewFlats 1606',
                'title' => 'Vos identifiants d’accès et guide d’arrivée',
                'pin_label' => 'Code PIN de la serrure connectée',
                'btn' => 'Ouvrir le guide du voyageur',
                'parking_label' => 'Parking Assigné',
            ],
            'it' => [
                'subject' => 'Credenziali di accesso e guida all’arrivo - OceanViewFlats 1606',
                'title' => 'Le tue credenziali di accesso e guida all’arrivo',
                'pin_label' => 'PIN della serratura smart',
                'btn' => 'Apri la Guida dell’Ospite',
                'parking_label' => 'Parcheggio Assegnato',
            ],
            'de' => [
                'subject' => 'Zugangsdaten & Ankunftsleitfaden - OceanViewFlats 1606',
                'title' => 'Ihre Zugangsdaten & Ankunftsleitfaden',
                'pin_label' => 'Smart Lock Tür-PIN',
                'btn' => 'Gäste-Leitfaden öffnen',
                'parking_label' => 'Zugewiesener Parkplatz',
            ],
            'ja' => [
                'subject' => 'アクセス認証情報とご到着案内 - OceanViewFlats 1606',
                'title' => 'アクセス認証情報とご到着案内',
                'pin_label' => 'スマートロック ドア暗証番号',
                'btn' => 'ゲストガイドを開く',
                'parking_label' => '専用駐車場',
            ],
        ];

        foreach ($expectations as $lang => $expected) {
            $subject = $this->renderer->renderSubject($reservation, $lang);
            $this->assertSame($expected['subject'], $subject, "Subject mismatch for {$lang}");

            $html = $this->renderer->renderHtml(
                reservation: $reservation,
                doorCode: $doorCode,
                lang: $lang,
                parkingSpot: '87'
            );
            $this->assertStringContainsString($expected['title'], $html, "Title mismatch for {$lang}");
            $this->assertStringContainsString($expected['pin_label'], $html, "PIN label mismatch for {$lang}");
            $this->assertStringContainsString($expected['btn'], $html, "Button mismatch for {$lang}");
            $this->assertStringContainsString($expected['parking_label'], $html, "Parking label mismatch for {$lang}");
            $this->assertStringContainsString('#87', $html, "Parking spot missing for {$lang}");
            $this->assertStringContainsString($doorCode, $html, "Door code missing for {$lang}");
            $this->assertStringContainsString("lang={$lang}", $html, "Guide URL lang mismatch for {$lang}");

            $plain = $this->renderer->renderPlainText(
                reservation: $reservation,
                doorCode: $doorCode,
                lang: $lang,
                parkingSpot: '87'
            );
            $this->assertStringContainsString($expected['title'], $plain, "Plain title mismatch for {$lang}");
            $this->assertStringContainsString($expected['parking_label'], $plain, "Plain parking label missing for {$lang}");
            $this->assertStringContainsString('#87', $plain, "Plain parking spot missing for {$lang}");
            $this->assertStringContainsString($doorCode, $plain, "Plain door code missing for {$lang}");
        }
    }

    public function testFallbackToEnglishWhenLanguageUnknown(): void
    {
        $reservation = $this->createReservation('xyz');
        $doorCode = '1234567#';

        $subject = $this->renderer->renderSubject($reservation, 'unknown');
        $this->assertSame('Access Credentials & Arrival Guide - OceanViewFlats 1606', $subject);

        $html = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'unknown'
        );
        $this->assertStringContainsString('Your Access Credentials & Arrival Guide', $html);
        $this->assertStringContainsString('Open Guest Guide', $html);
    }

    public function testResolvesSpanishFromGuestEmailTld(): void
    {
        $reservation = $this->createReservation('', 'carlos@empresa.com.co', 'Carlos Gómez');
        $doorCode = '9876543#';

        $subject = $this->renderer->renderSubject($reservation);
        $this->assertSame('Credenciales de Acceso y Guía de Llegada - OceanViewFlats 1606', $subject);

        $html = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode
        );
        $this->assertStringContainsString('Tus Credenciales de Acceso y Guía de Llegada', $html);
        $this->assertStringContainsString('Abrir Guía del Huésped', $html);
    }

    public function testCustomRecipientNameAndExplicitGuideUrl(): void
    {
        $reservation = $this->createReservation('en', 'booking@company.com', 'Corporate Account');
        $doorCode = '5556667#';
        $customGuideUrl = 'https://custom.oceanviewflats.com/guide/?secret=special';

        $html = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            guideUrl: $customGuideUrl,
            lang: 'en',
            recipientName: 'Dr. John Watson'
        );

        $this->assertStringContainsString('Dr. John Watson', $html);
        $this->assertStringContainsString(htmlspecialchars($customGuideUrl, ENT_QUOTES, 'UTF-8'), $html);

        $plain = $this->renderer->renderPlainText(
            reservation: $reservation,
            doorCode: $doorCode,
            guideUrl: $customGuideUrl,
            lang: 'en',
            recipientName: 'Dr. John Watson'
        );

        $this->assertStringContainsString('Dr. John Watson', $plain);
        $this->assertStringContainsString($customGuideUrl, $plain);
    }

    public function testRendersAssignedParkingWhenProvided(): void
    {
        $reservation = $this->createReservation('en');
        $doorCode = '1234567#';

        $html = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: '87'
        );

        $this->assertStringContainsString('<div class="item-row"><span>Assigned Parking</span><strong>#87</strong></div>', $html);

        $plain = $this->renderer->renderPlainText(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: '87'
        );

        $this->assertStringContainsString("Assigned Parking:         #87", $plain);

        // Also handles pre-formatted hash `#95`
        $html95 = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: '#95'
        );
        $this->assertStringContainsString('<div class="item-row"><span>Assigned Parking</span><strong>#95</strong></div>', $html95);
    }

    public function testOmitsAssignedParkingWhenNullOrEmpty(): void
    {
        $reservation = $this->createReservation('en');
        $doorCode = '1234567#';

        // null parkingSpot
        $htmlNull = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: null
        );
        $this->assertStringNotContainsString('Assigned Parking', $htmlNull);

        $plainNull = $this->renderer->renderPlainText(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: null
        );
        $this->assertStringNotContainsString('Assigned Parking', $plainNull);

        // whitespace/empty parkingSpot
        $htmlEmpty = $this->renderer->renderHtml(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: '   '
        );
        $this->assertStringNotContainsString('Assigned Parking', $htmlEmpty);

        $plainEmpty = $this->renderer->renderPlainText(
            reservation: $reservation,
            doorCode: $doorCode,
            lang: 'en',
            parkingSpot: '   '
        );
        $this->assertStringNotContainsString('Assigned Parking', $plainEmpty);
    }
}
