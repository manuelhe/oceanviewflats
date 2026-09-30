<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;

final class AvailabilityEndpointTest extends TestCase
{
    private string $cacheDir;
    private string $cacheFile1606;
    private ?string $originalCacheContent = null;

    protected function setUp(): void
    {
        $this->cacheDir = dirname(__DIR__, 3) . '/public/cache';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $this->cacheFile1606 = $this->cacheDir . '/avail_1606.json';
        if (file_exists($this->cacheFile1606)) {
            $this->originalCacheContent = file_get_contents($this->cacheFile1606) ?: null;
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalCacheContent !== null) {
            file_put_contents($this->cacheFile1606, $this->originalCacheContent);
        }
    }

    public function testAvailabilityEndpointRejectsInvalidProperty(): void
    {
        $res = $this->callEndpoint(['property' => 'invalid_prop']);

        $this->assertSame(0, $res['exitCode']);
        $this->assertIsArray($res['json']);
        $this->assertArrayHasKey('error', $res['json']);
        $this->assertStringContainsString('Invalid property ID', $res['json']['error']);
    }

    public function testAvailabilityEndpointReturnsCachedChannelBlocks(): void
    {
        // Populate cache file to avoid network curl
        $testDates = ['2026-11-10', '2026-11-11', '2026-11-12'];
        file_put_contents($this->cacheFile1606, json_encode($testDates));

        $res = $this->callEndpoint(['property' => '1606']);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertIsArray($res['json']);
        foreach ($testDates as $date) {
            $this->assertContains($date, $res['json']);
        }
    }

    public function testAvailabilityEndpointReturnsMergedChannelBlocksAndDirectReservations(): void
    {
        $testDates = ['2026-11-10', '2026-11-11'];
        file_put_contents($this->cacheFile1606, json_encode($testDates));

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
$pdo->exec("CREATE TABLE admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE
)");
$pdo->exec("CREATE TABLE calendar_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    property_id TEXT,
    start_date TEXT,
    end_date TEXT,
    reason TEXT,
    created_by INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO reservations (reservation_uid, property_id, guest_name, guest_email, check_in, check_out, total_price, status, created_at)
    VALUES ('ovf_direct_1', '1606', 'Direct Guest', 'guest@example.com', '2026-11-20', '2026-11-23', 1500000, 'confirmed', datetime('now'))");
$GLOBALS['TEST_PDO'] = $pdo;
PHP;

        $res = $this->callEndpoint(['property' => '1606'], $setupPdoCode);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertIsArray($res['json']);
        // Channel block dates
        $this->assertContains('2026-11-10', $res['json']);
        $this->assertContains('2026-11-11', $res['json']);
        // Direct reservation nights (Nov 20, 21, 22)
        $this->assertContains('2026-11-20', $res['json']);
        $this->assertContains('2026-11-21', $res['json']);
        $this->assertContains('2026-11-22', $res['json']);
        // Exclusive checkout date is NOT blocked
        $this->assertNotContains('2026-11-23', $res['json']);
    }

    /**
     * @param array<string, string> $params
     * @return array{exitCode: int, stdout: string, stderr: string, json: ?array<int|string, mixed>}
     */
    private function callEndpoint(array $params = [], string $prependCode = ''): array
    {
        $phpCode = sprintf(
            '%s; $_SERVER["REQUEST_METHOD"] = "GET"; $_GET = %s; require %s;',
            $prependCode,
            var_export($params, true),
            var_export(dirname(__DIR__, 3) . '/public/api/availability.php', true)
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
            'json' => json_decode((string) $stdout, true),
        ];
    }
}
