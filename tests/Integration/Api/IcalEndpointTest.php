<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

final class IcalEndpointTest extends TestCase
{
    public function testIcalEndpointRejectsInvalidProperty(): void
    {
        $res = $this->callEndpoint(['property' => 'invalid_prop']);

        $this->assertSame(0, $res['exitCode']);
        $this->assertStringContainsString('Bad Request: Invalid or missing property parameter', $res['stdout']);
    }

    public function testIcalEndpointRejectsMissingProperty(): void
    {
        $res = $this->callEndpoint([]);

        $this->assertSame(0, $res['exitCode']);
        $this->assertStringContainsString('Bad Request: Invalid or missing property parameter', $res['stdout']);
    }

    public function testIcalEndpointExportsActiveConfirmedReservation(): void
    {
        $setupPdoCode = <<<'PHP'
$pdo = new PDO("sqlite::memory:");
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
    status TEXT,
    payment_method_id TEXT,
    mercadopago_payment_id TEXT,
    payment_status TEXT,
    payment_detail TEXT,
    lang TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, check_in, check_out, total_price, status, created_at)
    VALUES ('ovf_test_uid', '1606', 'John Doe', 'john@example.com', '2026-08-01', '2026-08-05', 1500000, 'confirmed', datetime('now'))");
$GLOBALS['TEST_PDO'] = $pdo;
PHP;

        $res = $this->callEndpoint(['property' => '1606'], $setupPdoCode);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $res['stdout']);
        $this->assertStringContainsString('UID:ovf_test_uid@oceanviewflats.com', $res['stdout']);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260801', $res['stdout']);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260805', $res['stdout']);
        $this->assertStringContainsString('SUMMARY:Blocked - OceanViewFlats Direct Booking', $res['stdout']);
        $this->assertStringContainsString('END:VCALENDAR', $res['stdout']);
    }

    public function testIcalEndpointIgnoresCancelledReservations(): void
    {
        $setupPdoCode = <<<'PHP'
$pdo = new PDO("sqlite::memory:");
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
    status TEXT,
    payment_method_id TEXT,
    mercadopago_payment_id TEXT,
    payment_status TEXT,
    payment_detail TEXT,
    lang TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, check_in, check_out, total_price, status, created_at)
    VALUES ('ovf_cancelled_uid', '1606', 'Cancelled Guest', 'john@example.com', '2026-08-01', '2026-08-05', 1500000, 'cancelled', datetime('now'))");
$GLOBALS['TEST_PDO'] = $pdo;
PHP;

        $res = $this->callEndpoint(['property' => '1606'], $setupPdoCode);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $res['stdout']);
        $this->assertStringNotContainsString('ovf_cancelled_uid', $res['stdout']);
        $this->assertStringContainsString('END:VCALENDAR', $res['stdout']);
    }

    /**
     * @param array<string, string> $params
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function callEndpoint(array $params = [], string $prependCode = ''): array
    {
        $phpCode = sprintf(
            '%s; $_SERVER["REQUEST_METHOD"] = "GET"; $_GET = %s; require %s;',
            $prependCode,
            var_export($params, true),
            var_export(dirname(__DIR__, 3) . '/public/api/ical.php', true)
        );

        $process = proc_open(
            ['php', '-r', $phpCode],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }
}
