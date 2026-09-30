<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Fulfillment\GuestRegistrySubmission;
use OceanViewFlats\Domain\Fulfillment\HostRegistryEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\OccupantDetails;
use PHPUnit\Framework\TestCase;

final class HostRegistryEmailRendererTest extends TestCase
{
    private DateTimeImmutable $fixedNow;
    private HostRegistryEmailRenderer $renderer;

    protected function setUp(): void
    {
        $this->fixedNow = new DateTimeImmutable('2026-10-01 14:30:00');
        $this->renderer = new HostRegistryEmailRenderer($this->fixedNow);
    }

    private function createSubmission(
        string $propertyId = '1606',
        ?string $carPlates = 'XYZ-123',
        ?string $carModel = 'Toyota RAV4'
    ): GuestRegistrySubmission {
        $occupants = [
            new OccupantDetails(
                index: 1,
                name: 'Jane Doe',
                age: 34,
                docType: 'Passport',
                docNum: 'US12345678'
            ),
            new OccupantDetails(
                index: 2,
                name: 'John Doe',
                age: 36,
                docType: 'Driver License',
                docNum: 'DL887766'
            ),
        ];

        return new GuestRegistrySubmission(
            reservationCode: 'res_abc_999',
            propertyId: $propertyId,
            checkIn: '2026-11-01',
            checkOut: '2026-11-05',
            occupants: $occupants,
            carPlates: $carPlates,
            carModel: $carModel,
            ipAddress: '190.24.15.2',
            lang: 'en'
        );
    }

    public function testRenderSubjectWithProperty(): void
    {
        $submission = $this->createSubmission('1606');
        $subject = $this->renderer->renderSubject($submission);

        $this->assertSame('OceanViewFlats Guest Registry Report - OceanViewFlats 1606', $subject);
    }

    public function testRenderSubjectWithoutProperty(): void
    {
        $submission = $this->createSubmission('');
        $subject = $this->renderer->renderSubject($submission);

        $this->assertSame('OceanViewFlats Guest Registry Report - Unspecified', $subject);
    }

    public function testRenderSubjectStripsNewlines(): void
    {
        $submission = $this->createSubmission("1606\r\nInjected Header: evil");
        $subject = $this->renderer->renderSubject($submission);

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
        $this->assertSame('OceanViewFlats Guest Registry Report - OceanViewFlats 1606Injected Header: evil', $subject);
    }

    public function testRenderPlainTextWithAllSectionsAndVehicleInfo(): void
    {
        $submission = $this->createSubmission('1606', 'XYZ-123', 'Toyota RAV4');
        $text = $this->renderer->renderPlainText(
            submission: $submission,
            doorCode: '749201#',
            spreadsheetSuccess: true
        );

        // Header
        $this->assertStringContainsString("OceanViewFlats Official Guest Registry Report\n", $text);

        // Stay information
        $this->assertStringContainsString('STAY INFORMATION', $text);
        $this->assertStringContainsString('Property:      OceanViewFlats 1606', $text);
        $this->assertStringContainsString('Check-in:      2026-11-01', $text);
        $this->assertStringContainsString('Check-out:     2026-11-05', $text);
        $this->assertStringContainsString('Total Guests:  2', $text);

        // Vehicle info
        $this->assertStringContainsString('VEHICLE INFORMATION (OPTIONAL)', $text);
        $this->assertStringContainsString('Plates:        XYZ-123', $text);
        $this->assertStringContainsString('Make & Model:  Toyota RAV4', $text);

        // Registered guests
        $this->assertStringContainsString('REGISTERED GUESTS DETAILS', $text);
        $this->assertStringContainsString("Guest #1:\n  Name:     Jane Doe\n  ID/Doc:   Passport (US12345678)\n  Age:      34", $text);
        $this->assertStringContainsString("Guest #2:\n  Name:     John Doe\n  ID/Doc:   Driver License (DL887766)\n  Age:      36", $text);

        // Smart lock access PIN
        $this->assertStringContainsString('SMART LOCK ACCESS PIN (ACTION REQUIRED)', $text);
        $this->assertStringContainsString('Generated Door PIN:  749201#', $text);
        $this->assertStringContainsString('Primary Guest Doc:   US12345678', $text);
        $this->assertStringContainsString('Lock Instructions:   Program this 7-digit code (ending in #)', $text);
        $this->assertStringContainsString('into the apartment smart lock companion app.', $text);

        // System logs
        $this->assertStringContainsString('SYSTEM LOGS', $text);
        $this->assertStringContainsString('Submission IP:  190.24.15.2', $text);
        $this->assertStringContainsString('Timestamp:      2026-10-01 14:30:00', $text);
        $this->assertStringContainsString('Google Sheet:   Recorded successfully.', $text);
    }

    public function testRenderPlainTextWithoutVehicleInfo(): void
    {
        $submission = $this->createSubmission('1707', null, null);
        $text = $this->renderer->renderPlainText(
            submission: $submission,
            doorCode: '112233#',
            spreadsheetSuccess: true
        );

        $this->assertStringNotContainsString('VEHICLE INFORMATION (OPTIONAL)', $text);
        $this->assertStringContainsString('Property:      OceanViewFlats 1707', $text);
        $this->assertStringContainsString('Generated Door PIN:  112233#', $text);
    }

    public function testRenderPlainTextWithSpreadsheetFailure(): void
    {
        $submission = $this->createSubmission();
        $text = $this->renderer->renderPlainText(
            submission: $submission,
            doorCode: '749201#',
            spreadsheetSuccess: false,
            spreadsheetError: 'HTTP 502'
        );

        $this->assertStringContainsString('Google Sheet:   FAILED (HTTP 502)', $text);
    }

    public function testRenderPlainTextWithSpreadsheetNotConfigured(): void
    {
        $submission = $this->createSubmission();
        $text = $this->renderer->renderPlainText(
            submission: $submission,
            doorCode: '749201#',
            spreadsheetSuccess: false,
            spreadsheetError: null
        );

        $this->assertStringContainsString('Google Sheet:   Not configured.', $text);
    }

    public function testRenderHtmlProducesFormattedDocument(): void
    {
        $submission = $this->createSubmission('1606', 'ABC-999', 'Hyundai Tucson');
        $html = $this->renderer->renderHtml(
            submission: $submission,
            doorCode: '556677#',
            spreadsheetSuccess: true
        );

        // Structure & Sections
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Guest Registry Report', $html);
        $this->assertStringContainsString('Stay Information', $html);
        $this->assertStringContainsString('OceanViewFlats 1606', $html);
        $this->assertStringContainsString('Vehicle Information', $html);
        $this->assertStringContainsString('ABC-999', $html);
        $this->assertStringContainsString('Hyundai Tucson', $html);

        // Occupants
        $this->assertStringContainsString('Registered Guests Details', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('Passport (US12345678)', $html);
        $this->assertStringContainsString('John Doe', $html);
        $this->assertStringContainsString('Driver License (DL887766)', $html);

        // PIN box
        $this->assertStringContainsString('Smart Lock Access PIN (Action Required)', $html);
        $this->assertStringContainsString('556677#', $html);
        $this->assertStringContainsString('<code>US12345678</code>', $html);
        $this->assertStringContainsString('Program this 7-digit code (ending in #) into the apartment smart lock companion app.', $html);

        // System logs
        $this->assertStringContainsString('Submission IP', $html);
        $this->assertStringContainsString('190.24.15.2', $html);
        $this->assertStringContainsString('2026-10-01 14:30:00', $html);
        $this->assertStringContainsString('Recorded successfully.', $html);
    }

    public function testRenderHtmlEscapesUserInputs(): void
    {
        $maliciousOccupant = new OccupantDetails(
            index: 1,
            name: '<script>alert("hacked")</script>',
            age: 25,
            docType: 'Other ID',
            docNum: '<img src=x onerror=alert(1)>'
        );

        $submission = new GuestRegistrySubmission(
            reservationCode: 'res_xss',
            propertyId: '<b>1606</b>',
            checkIn: '2026-11-01',
            checkOut: '2026-11-05',
            occupants: [$maliciousOccupant],
            carPlates: '<script>',
            carModel: '<b>Model</b>',
            ipAddress: '<test-ip>',
            lang: 'es'
        );

        $html = $this->renderer->renderHtml(
            submission: $submission,
            doorCode: '123456#',
            spreadsheetSuccess: true
        );

        $this->assertStringNotContainsString('<script>alert("hacked")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<b>1606</b>', $html);
        $this->assertStringNotContainsString('<test-ip>', $html);

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;hacked&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('OceanViewFlats &lt;b&gt;1606&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;test-ip&gt;', $html);
    }
}
