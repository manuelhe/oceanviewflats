<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Integration\Api;

use PDO;
use PHPUnit\Framework\TestCase;

final class GuestRegistryEndpointTest extends TestCase
{
    private string $dbPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/test_registry_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->seedFixtures();

        $rateLimitFile = sys_get_temp_dir() . '/ovf_registry_rate_limits.json';
        if (file_exists($rateLimitFile)) {
            @unlink($rateLimitFile);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $rateLimitFile = sys_get_temp_dir() . '/ovf_registry_rate_limits.json';
        if (file_exists($rateLimitFile)) {
            @unlink($rateLimitFile);
        }

        if (isset($this->dbPath) && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE reservations (
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
                mercadopago_preference_id TEXT,
                mercadopago_payment_id TEXT,
                payment_status TEXT,
                payment_detail TEXT,
                notes TEXT,
                lang TEXT,
                registry_completed INTEGER DEFAULT 0,
                registry_completed_at TEXT,
                door_code TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE guest_registries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                reservation_uid TEXT,
                property_id TEXT,
                check_in TEXT,
                check_out TEXT,
                guest_count INTEGER DEFAULT 1,
                guests_payload TEXT,
                car_plates TEXT,
                car_model TEXT,
                ip_address TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE admin_audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER,
                action TEXT,
                entity_type TEXT,
                entity_id TEXT,
                payload_before TEXT,
                payload_after TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    private function seedFixtures(): void
    {
        $this->pdo->exec("
            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, payment_status, registry_completed
            ) VALUES (
                'ovf_conf_100', '1606', 'Maria Gomez', 'maria@example.com', '+573001234567',
                '2026-11-15', '2026-11-20', 1600000.0, 'confirmed', 'approved', 0
            );

            INSERT INTO reservations (
                reservation_uid, property_id, guest_name, guest_email, guest_phone,
                check_in, check_out, total_price, status, payment_status, registry_completed
            ) VALUES (
                'ovf_canc_200', '1606', 'Cancelled Guest', 'canc@example.com', '+573009998877',
                '2026-12-01', '2026-12-05', 1200000.0, 'cancelled', 'refunded', 0
            );
        ");
    }

    /**
     * @return array{captcha_challenge: string, captcha_signature: string, captcha_response: string}
     */
    private function createValidCaptcha(string $secret = 'securesaltsecret'): array
    {
        $a = 4;
        $b = 5;
        $challenge = "{$a} + {$b}";
        $signature = hash_hmac('sha256', $challenge, $secret);

        return [
            'captcha_challenge' => $challenge,
            'captcha_signature' => $signature,
            'captcha_response' => (string) ($a + $b),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createValidRegistryData(array $overrides = []): array
    {
        $captcha = $this->createValidCaptcha();

        return array_merge([
            'reservation_code' => 'ovf_conf_100',
            'property' => '1606',
            'check_in' => '2026-11-15',
            'check_out' => '2026-11-20',
            'guest_count' => 2,
            'guest_name_1' => 'Maria Gomez',
            'guest_age_1' => 34,
            'guest_doc_type_1' => 'Passport',
            'guest_doc_num_1' => 'US12345678',
            'guest_name_2' => 'Carlos Gomez',
            'guest_age_2' => 36,
            'guest_doc_type_2' => 'Passport',
            'guest_doc_num_2' => 'US87654321',
            'car_plates' => 'ABC-123',
            'car_model' => 'Mazda CX-5',
            'lang' => 'en',
        ], $captcha, $overrides);
    }

    public function testRegistryEndpointRejectsNonPostMethodWith405(): void
    {
        $res = $this->callRegistryEndpoint([], 'GET');

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(405, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('Method Not Allowed', $res['json']['error']);
    }

    public function testRegistryEndpointEnforcesRefererCheckForDirectBrowserAccess(): void
    {
        $res = $this->callRegistryEndpoint(
            $this->createValidRegistryData(),
            'POST',
            [
                'HTTP_REFERER' => '',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            ]
        );

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('Direct access to this processing script is not permitted.', $res['json']['error']);
    }

    public function testRegistryEndpointRejectsUnauthorizedRefererHost(): void
    {
        $res = $this->callRegistryEndpoint(
            $this->createValidRegistryData(),
            'POST',
            [
                'HTTP_REFERER' => 'https://malicious-phishing.com/attack',
            ]
        );

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertSame('Unauthorized access origin.', $res['json']['error']);
    }

    public function testRegistryEndpointCatchesHoneypotSubmission(): void
    {
        $resWithHp = $this->callRegistryEndpoint($this->createValidRegistryData([
            'website_hp' => 'http://spam-bot.xyz',
        ]));

        $this->assertSame(0, $resWithHp['exitCode'], $resWithHp['stderr']);
        $this->assertSame(200, $resWithHp['statusCode']);
        $this->assertIsArray($resWithHp['json']);
        $this->assertTrue($resWithHp['json']['success']);
        $this->assertSame('Registration successfully processed.', $resWithHp['json']['message']);

        // Database must remain unaffected
        $regCount = (int) $this->pdo->query('SELECT COUNT(*) FROM guest_registries')->fetchColumn();
        $this->assertSame(0, $regCount);

        $resWithUrl = $this->callRegistryEndpoint($this->createValidRegistryData([
            'website_url' => 'http://spam-bot.xyz',
        ]));

        $this->assertSame(0, $resWithUrl['exitCode'], $resWithUrl['stderr']);
        $this->assertSame(200, $resWithUrl['statusCode']);
        $this->assertIsArray($resWithUrl['json']);
        $this->assertTrue($resWithUrl['json']['success']);
        $this->assertSame('Registration successfully processed.', $resWithUrl['json']['message']);

        $regCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM guest_registries')->fetchColumn();
        $this->assertSame(0, $regCountAfter);
    }

    public function testRegistryEndpointRejectsInvalidCaptchaChallenge(): void
    {
        // 1. Wrong response calculation
        $wrongResponseData = $this->createValidRegistryData([
            'captcha_challenge' => '4 + 5',
            'captcha_signature' => hash_hmac('sha256', '4 + 5', 'securesaltsecret'),
            'captcha_response' => '99',
        ]);
        $resWrong = $this->callRegistryEndpoint($wrongResponseData);

        $this->assertSame(0, $resWrong['exitCode'], $resWrong['stderr']);
        $this->assertIsArray($resWrong['json']);
        $this->assertFalse($resWrong['json']['success']);
        $this->assertNotEmpty($resWrong['json']['error']);

        // 2. Tampered signature
        $badSignData = $this->createValidRegistryData([
            'captcha_challenge' => '4 + 5',
            'captcha_signature' => 'forged_invalid_signature',
            'captcha_response' => '9',
        ]);
        $resSign = $this->callRegistryEndpoint($badSignData);

        $this->assertSame(0, $resSign['exitCode'], $resSign['stderr']);
        $this->assertIsArray($resSign['json']);
        $this->assertFalse($resSign['json']['success']);
        $this->assertNotEmpty($resSign['json']['error']);

        // Ensure database was not altered
        $regCount = (int) $this->pdo->query('SELECT COUNT(*) FROM guest_registries')->fetchColumn();
        $this->assertSame(0, $regCount);
    }

    public function testValidSubmissionSucceedsEndToEnd(): void
    {
        $payload = $this->createValidRegistryData();

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(200, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertTrue($res['json']['success']);
        $this->assertSame('Registration successfully processed.', $res['json']['message']);

        // Validate Door PIN format per ADR 0001
        $doorCode = $res['json']['door_code'] ?? null;
        $this->assertIsString($doorCode);
        $this->assertStringEndsWith('#', $doorCode);
        $this->assertMatchesRegularExpression('/^[0-9]{4,10}#$/', $doorCode);
        // Primary doc "US12345678" -> last 6 digits "345678" -> padded with leading 0 -> "0345678#"
        $this->assertSame('0345678#', $doorCode);

        // Guide URL
        $guideUrl = $res['json']['guide_url'] ?? null;
        $this->assertIsString($guideUrl);
        $this->assertStringContainsString('ovf_conf_100', $guideUrl);
        $this->assertStringContainsString('lang=en', $guideUrl);

        // Reservation code
        $this->assertSame('ovf_conf_100', $res['json']['reservation_code'] ?? null);

        // Verify Database updates on reservations table
        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE reservation_uid = :uid');
        $stmt->execute([':uid' => 'ovf_conf_100']);
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertSame(1, (int) $row['registry_completed']);
        $this->assertSame('0345678#', $row['door_code']);
        $this->assertNotNull($row['registry_completed_at']);

        // Verify guest_registries table entry
        $stmtReg = $this->pdo->prepare('SELECT * FROM guest_registries WHERE reservation_uid = :uid');
        $stmtReg->execute([':uid' => 'ovf_conf_100']);
        $reg = $stmtReg->fetch();
        $this->assertIsArray($reg);
        $this->assertSame('1606', $reg['property_id']);
        $this->assertSame('2026-11-15', $reg['check_in']);
        $this->assertSame('2026-11-20', $reg['check_out']);
        $this->assertSame(2, (int) $reg['guest_count']);
        $this->assertSame('ABC-123', $reg['car_plates']);
        $this->assertSame('Mazda CX-5', $reg['car_model']);
        $this->assertSame('127.0.0.1', $reg['ip_address']);

        $occupantsPayload = json_decode((string) $reg['guests_payload'], true);
        $this->assertIsArray($occupantsPayload);
        $this->assertCount(2, $occupantsPayload);
        $this->assertSame('Maria Gomez', $occupantsPayload[0]['name']);
        $this->assertSame('US12345678', $occupantsPayload[0]['doc_num']);

        // Verify admin_audit_logs entry
        $stmtAudit = $this->pdo->prepare("SELECT * FROM admin_audit_logs WHERE action = 'guest_registry_submitted'");
        $stmtAudit->execute();
        $audit = $stmtAudit->fetch();
        $this->assertIsArray($audit);
        $this->assertSame('reservation', $audit['entity_type']);
        $this->assertSame('ovf_conf_100', $audit['entity_id']);
    }

    public function testValidSubmissionWithStructuredOccupantsPayloadSucceeds(): void
    {
        $captcha = $this->createValidCaptcha();
        $payload = array_merge([
            'reservation_code' => 'ovf_conf_100',
            'property_id' => '1606',
            'check_in' => '2026-11-15',
            'check_out' => '2026-11-20',
            'occupants' => [
                [
                    'index' => 1,
                    'name' => 'Maria Gomez',
                    'age' => 34,
                    'doc_type' => 'Passport',
                    'doc_num' => 'US12345678',
                ],
            ],
            'lang' => 'es',
        ], $captcha);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(200, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertTrue($res['json']['success']);
        $this->assertSame('0345678#', $res['json']['door_code']);
        $this->assertStringContainsString('lang=es', (string) $res['json']['guide_url']);
    }

    public function testSubmitRegistryMatchesByPropertyAndStayDatesWhenCodeIsEmpty(): void
    {
        $payload = $this->createValidRegistryData([
            'reservation_code' => '',
            'property' => '1606',
            'check_in' => '2026-11-15',
            'check_out' => '2026-11-20',
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(200, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertTrue($res['json']['success']);
        $this->assertSame('ovf_conf_100', $res['json']['reservation_code']);
        $this->assertSame('0345678#', $res['json']['door_code']);
    }

    public function testInvalidSubmissionRejectsZeroOccupantsWith400(): void
    {
        $payload = $this->createValidRegistryData([
            'occupants' => [],
            'guest_count' => 0,
        ]);
        // Remove flat keys
        unset(
            $payload['guest_name_1'], $payload['guest_age_1'], $payload['guest_doc_type_1'], $payload['guest_doc_num_1'],
            $payload['guest_name_2'], $payload['guest_age_2'], $payload['guest_doc_type_2'], $payload['guest_doc_num_2']
        );

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertNotEmpty($res['json']['errors']);
        $this->assertContains('A reservation must register between 1 and 6 guests.', $res['json']['errors']);
    }

    public function testInvalidSubmissionRejectsAgeOver120With400(): void
    {
        $payload = $this->createValidRegistryData([
            'guest_age_1' => 135,
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertStringContainsString('age', strtolower((string) $res['json']['error']));
    }

    public function testInvalidSubmissionRejectsReversedDatesWith400(): void
    {
        $payload = $this->createValidRegistryData([
            'check_in' => '2026-11-20',
            'check_out' => '2026-11-15',
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertNotEmpty($res['json']['errors']);
    }

    public function testInvalidSubmissionRejectsMalformedDateFormatWith400(): void
    {
        $payload = $this->createValidRegistryData([
            'check_in' => 'not-a-valid-date',
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertNotEmpty($res['json']['errors']);
    }

    public function testUnmatchedReservationReturns400Error(): void
    {
        $payload = $this->createValidRegistryData([
            'reservation_code' => 'ovf_nonexistent_999',
            'property' => '1707',
            'check_in' => '2027-01-01',
            'check_out' => '2027-01-05',
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertStringContainsString('matches the provided booking details', (string) $res['json']['error']);

        $regCount = (int) $this->pdo->query('SELECT COUNT(*) FROM guest_registries')->fetchColumn();
        $this->assertSame(0, $regCount);
    }

    public function testCancelledReservationRejectionReturns400(): void
    {
        $payload = $this->createValidRegistryData([
            'reservation_code' => 'ovf_canc_200',
        ]);

        $res = $this->callRegistryEndpoint($payload);

        $this->assertSame(0, $res['exitCode'], $res['stderr']);
        $this->assertSame(400, $res['statusCode']);
        $this->assertIsArray($res['json']);
        $this->assertFalse($res['json']['success']);
        $this->assertStringContainsString('cancelled', strtolower((string) $res['json']['error']));
    }

    /**
     * Executes public/api/registry-processor.php via a sub-process to test HTTP guards in complete isolation.
     *
     * @param array<string, mixed> $bodyData
     * @param string $method
     * @param array<string, string> $serverVars
     * @param string $prependCode
     * @return array{exitCode: int, stdout: string, stderr: string, statusCode: int, json: ?array<string, mixed>}
     */
    private function callRegistryEndpoint(
        array $bodyData = [],
        string $method = 'POST',
        array $serverVars = [],
        string $prependCode = ''
    ): array {
        $rateLimitFile = sys_get_temp_dir() . '/ovf_registry_rate_limits.json';
        if (file_exists($rateLimitFile)) {
            @unlink($rateLimitFile);
        }

        $defaultServer = [
            'REQUEST_METHOD' => $method,
            'HTTP_HOST' => 'oceanviewflats.com',
            'HTTP_REFERER' => 'https://oceanviewflats.com/registry',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $mergedServer = array_merge($defaultServer, $serverVars);

        $jsonInput = json_encode($bodyData);

        $dbInit = sprintf(
            '$GLOBALS["TEST_PDO"] = new PDO("sqlite:%s"); $GLOBALS["TEST_PDO"]->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $GLOBALS["TEST_PDO"]->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);',
            $this->dbPath
        );

        $fullPrepend = sprintf(
            'register_shutdown_function(function() { fwrite(STDERR, "[STATUS:" . http_response_code() . "]"); }); %s; %s',
            $dbInit,
            $prependCode
        );

        $phpCode = sprintf(
            '%s; foreach (%s as $k => $v) { $_SERVER[$k] = $v; }; require %s;',
            $fullPrepend,
            var_export($mergedServer, true),
            var_export(dirname(__DIR__, 3) . '/public/api/registry-processor.php', true)
        );

        $process = proc_open(
            ['php', '-r', $phpCode],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if ($jsonInput !== false) {
            fwrite($pipes[0], $jsonInput);
        }
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $statusCode = 200;
        if (preg_match('/\[STATUS:(\d+)\]/', (string) $stderr, $matches)) {
            $statusCode = (int) $matches[1];
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
            'statusCode' => $statusCode,
            'json' => json_decode((string) $stdout, true),
        ];
    }
}
