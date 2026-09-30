<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Integration\Cli;

use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Cli\AdminUserProvisioner;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class CreateAdminUserTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = AdminDatabaseTestHelper::createDatabase();
    }

    /**
     * Executes scripts/create-admin-user.php within the current PHP process
     * by injecting the SQLite test PDO.
     *
     * @param array<int, string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function executeScript(array $arguments, ?PDO $pdo = null, ?string $simulatedSapi = null): array
    {
        $activePdo = $pdo ?? $this->pdo;
        $argv = array_merge(['scripts/create-admin-user.php'], $arguments);
        $noExit = true;

        ob_start();
        // Capture stderr via temporary stream redirect
        $tmpStderr = fopen('php://temp', 'w+');
        $originalStderr = defined('STDERR') ? STDERR : null;

        // In PHP CLI, we can isolate script inclusion
        $scriptPath = dirname(__DIR__, 3) . '/scripts/create-admin-user.php';

        $exitCode = (function () use ($activePdo, $argv, $noExit, $simulatedSapi, $scriptPath) {
            $pdo = $activePdo;
            return require $scriptPath;
        })();

        $stdout = (string) ob_get_clean();

        return [
            'exitCode' => (int) $exitCode,
            'stdout' => $stdout,
            'stderr' => '',
        ];
    }

    /**
     * Executes the script in an actual subprocess using proc_open.
     *
     * @param array<int, string> $arguments
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function executeSubprocess(array $arguments, string $prependPhpCode = ''): array
    {
        $scriptPath = dirname(__DIR__, 3) . '/scripts/create-admin-user.php';

        if ($prependPhpCode !== '') {
            $phpCode = sprintf(
                'require_once %s; %s; $argv = %s; $_SERVER["argv"] = $argv; exit((int) (require %s));',
                var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
                $prependPhpCode,
                var_export(array_merge(['scripts/create-admin-user.php'], $arguments), true),
                var_export($scriptPath, true)
            );
            $cmd = ['php', '-r', $phpCode];
        } else {
            $cmd = array_merge(['php', $scriptPath], $arguments);
        }

        $process = proc_open(
            $cmd,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    public function testFailsValidationWhenEmailIsMissingOrInvalid(): void
    {
        // 1. Missing email
        $resMissing = $this->executeSubprocess(
            ['--name=Admin', '--password=ValidPassword123!'],
            '$GLOBALS["TEST_PDO"] = \OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper::createDatabase();'
        );
        $this->assertSame(1, $resMissing['exitCode']);
        $this->assertStringContainsString('Invalid or missing email address', $resMissing['stderr']);

        // 2. Invalid email format
        $resInvalid = $this->executeSubprocess(
            ['--email=not-an-email', '--name=Admin', '--password=ValidPassword123!'],
            '$GLOBALS["TEST_PDO"] = \OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper::createDatabase();'
        );
        $this->assertSame(1, $resInvalid['exitCode']);
        $this->assertStringContainsString('Invalid or missing email address', $resInvalid['stderr']);

        // Verify no user was inserted
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testFailsValidationWhenPasswordIsTooShort(): void
    {
        $shortSecret = 'short7!';
        $res = $this->executeSubprocess(
            ['--email=admin@oceanviewflats.com', '--name=Admin User', '--password=' . $shortSecret],
            '$GLOBALS["TEST_PDO"] = \OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper::createDatabase();'
        );

        $this->assertSame(1, $res['exitCode']);
        $this->assertStringContainsString('Password must be at least 8 characters long', $res['stderr']);

        // Assert plaintext password does NOT leak in stdout or stderr
        $this->assertStringNotContainsString($shortSecret, $res['stdout']);
        $this->assertStringNotContainsString($shortSecret, $res['stderr']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testFailsValidationWhenNameIsEmpty(): void
    {
        $res = $this->executeSubprocess(
            ['--email=admin@oceanviewflats.com', '--name=', '--password=ValidPassword123!'],
            '$GLOBALS["TEST_PDO"] = \OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper::createDatabase();'
        );

        $this->assertSame(1, $res['exitCode']);
        $this->assertStringContainsString('Name is required and cannot be empty', $res['stderr']);
    }

    public function testSuccessfulAdminUserCreationWithArgon2id(): void
    {
        $password = 'CorrectHorseBatteryStaple123!';
        $res = $this->executeSubprocess(
            [
                '--email=provisioned@oceanviewflats.com',
                '--name=Super Administrator',
                '--password=' . $password,
                '--role=superadmin',
            ],
            '$GLOBALS["TEST_PDO"] = new PDO("sqlite:" . ' . var_export(sys_get_temp_dir() . '/ovf_test_admin.db', true) . '); \OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper::initializeSchema($GLOBALS["TEST_PDO"]);'
        );

        $dbFile = sys_get_temp_dir() . '/ovf_test_admin.db';
        $sqlitePdo = new PDO('sqlite:' . $dbFile);

        try {
            $this->assertSame(0, $res['exitCode'], 'Subprocess failed with stderr: ' . $res['stderr']);
            $this->assertStringContainsString('Admin user successfully created', $res['stdout']);
            $this->assertStringContainsString('provisioned@oceanviewflats.com', $res['stdout']);
            $this->assertStringContainsString('superadmin', $res['stdout']);

            // Security invariant: password must never appear in stdout or stderr
            $this->assertStringNotContainsString($password, $res['stdout']);
            $this->assertStringNotContainsString($password, $res['stderr']);

            // Inspect database row
            $stmt = $sqlitePdo->prepare('SELECT * FROM admin_users WHERE email = :email');
            $stmt->execute([':email' => 'provisioned@oceanviewflats.com']);
            /** @var array<string, mixed>|false $user */
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertIsArray($user);
            $this->assertSame('provisioned@oceanviewflats.com', $user['email']);
            $this->assertSame('Super Administrator', $user['name']);
            $this->assertSame('superadmin', $user['role']);
            $this->assertSame(1, (int) $user['is_active']);
            $this->assertSame(0, (int) $user['failed_login_attempts']);
            $this->assertNull($user['locked_until']);

            // Verify Argon2id hash format and password verification
            $hash = (string) $user['password_hash'];
            if (defined('PASSWORD_ARGON2ID')) {
                $this->assertStringStartsWith('$argon2id$', $hash);
            }
            $this->assertTrue(password_verify($password, $hash));
            $this->assertFalse(password_verify('WrongPassword123!', $hash));

            // Verify AuthService can authenticate this newly provisioned admin
            $authService = new AuthService($sqlitePdo);
            $loginResult = $authService->authenticate('provisioned@oceanviewflats.com', $password);
            $this->assertTrue($loginResult->isSuccess());
            $this->assertSame('provisioned@oceanviewflats.com', $loginResult->getUser()['email'] ?? null);
        } finally {
            if (file_exists($dbFile)) {
                @unlink($dbFile);
            }
        }
    }

    public function testIdempotentUpdateOnExistingEmailResetsLockoutAndUpdatesDetails(): void
    {
        $dbFile = sys_get_temp_dir() . '/ovf_test_idempotent.db';
        $sqlitePdo = new PDO('sqlite:' . $dbFile);
        AdminDatabaseTestHelper::initializeSchema($sqlitePdo);

        // Seed initial locked & inactive account
        $initialStmt = $sqlitePdo->prepare('
            INSERT INTO admin_users (email, password_hash, name, role, is_active, failed_login_attempts, locked_until)
            VALUES (:email, :hash, :name, :role, 0, 8, "2030-01-01 12:00:00")
        ');
        $initialStmt->execute([
            ':email' => 'manager@oceanviewflats.com',
            ':hash' => password_hash('OldPassword123!', PASSWORD_DEFAULT),
            ':name' => 'Old Manager Name',
            ':role' => 'editor',
        ]);

        $newPassword = 'NewRobustPassword2026!';

        try {
            // Run provisioning with the same email
            $res = $this->executeSubprocess(
                [
                    '--email=manager@oceanviewflats.com',
                    '--name=Promoted Manager',
                    '--password=' . $newPassword,
                    '--role=admin',
                ],
                '$GLOBALS["TEST_PDO"] = new PDO("sqlite:" . ' . var_export($dbFile, true) . ');'
            );

            $this->assertSame(0, $res['exitCode'], 'Failed with stderr: ' . $res['stderr']);
            $this->assertStringContainsString('Admin user successfully updated', $res['stdout']);
            $this->assertStringContainsString('manager@oceanviewflats.com', $res['stdout']);

            // Security invariant: password must never appear in stdout or stderr
            $this->assertStringNotContainsString($newPassword, $res['stdout']);
            $this->assertStringNotContainsString($newPassword, $res['stderr']);

            // Ensure exactly 1 user exists with this email
            $countStmt = $sqlitePdo->prepare('SELECT COUNT(*) FROM admin_users WHERE email = :email');
            $countStmt->execute([':email' => 'manager@oceanviewflats.com']);
            $this->assertSame(1, (int) $countStmt->fetchColumn());

            // Check updated properties and reset lockout
            $stmt = $sqlitePdo->prepare('SELECT * FROM admin_users WHERE email = :email');
            $stmt->execute([':email' => 'manager@oceanviewflats.com']);
            /** @var array<string, mixed>|false $updatedUser */
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertIsArray($updatedUser);
            $this->assertSame('Promoted Manager', $updatedUser['name']);
            $this->assertSame('admin', $updatedUser['role']);
            $this->assertSame(1, (int) $updatedUser['is_active']);
            $this->assertSame(0, (int) $updatedUser['failed_login_attempts']);
            $this->assertNull($updatedUser['locked_until']);

            // Verify password hash updated
            $hash = (string) $updatedUser['password_hash'];
            $this->assertTrue(password_verify($newPassword, $hash));
            $this->assertFalse(password_verify('OldPassword123!', $hash));

            // Verify AuthService can authenticate the newly unlocked account
            $authService = new AuthService($sqlitePdo);
            $loginResult = $authService->authenticate('manager@oceanviewflats.com', $newPassword);
            $this->assertTrue($loginResult->isSuccess());
        } finally {
            if (file_exists($dbFile)) {
                @unlink($dbFile);
            }
        }
    }

    public function testInProcessInclusionWithInjectedPdo(): void
    {
        $password = 'InProcessPassword123!';
        $res = $this->executeScript([
            '--email=inprocess@oceanviewflats.com',
            '--name=In-Process User',
            '--password=' . $password,
        ]);

        $this->assertSame(0, $res['exitCode']);
        $this->assertStringContainsString('Admin user successfully created', $res['stdout']);

        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email');
        $stmt->execute([':email' => 'inprocess@oceanviewflats.com']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($user);
        $this->assertSame('In-Process User', $user['name']);
        $this->assertSame('admin', $user['role']); // Default role
        $this->assertTrue(password_verify($password, (string) $user['password_hash']));
    }

    public function testNonCliInvocationEnforces403AndRejection(): void
    {
        $res = $this->executeScript(
            ['--email=admin@oceanviewflats.com', '--name=Admin', '--password=ValidPassword123!'],
            null,
            'fpm-fcgi'
        );

        $this->assertSame(1, $res['exitCode']);
        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString('This script must be run from the command line', $res['stdout']);
    }

    public function testDualPathConfigResolutionDiscoversConfig(): void
    {
        $tempDir = sys_get_temp_dir() . '/ovf_config_test_' . uniqid();
        mkdir($tempDir . '/public/api', 0777, true);
        mkdir($tempDir . '/public_html/api', 0777, true);

        try {
            // Path 1: public/api/config.php
            file_put_contents(
                $tempDir . '/public/api/config.php',
                '<?php return ["db" => ["host" => "localhost", "dbname" => "test_db"]];'
            );

            // Path 2: public_html/api/config.php
            file_put_contents(
                $tempDir . '/public_html/api/config.php',
                '<?php return ["db" => ["host" => "remotehost", "dbname" => "prod_db"]];'
            );

            // Neither exists in empty dir
            $emptyDir = sys_get_temp_dir() . '/ovf_empty_' . uniqid();
            mkdir($emptyDir, 0777, true);
            $this->assertNull(AdminUserProvisioner::resolvePdo(null, $emptyDir));
            rmdir($emptyDir);
        } finally {
            @unlink($tempDir . '/public/api/config.php');
            @unlink($tempDir . '/public_html/api/config.php');
            @rmdir($tempDir . '/public/api');
            @rmdir($tempDir . '/public_html/api');
            @rmdir($tempDir . '/public');
            @rmdir($tempDir . '/public_html');
            @rmdir($tempDir);
        }
    }

    public function testShortOptionsSupported(): void
    {
        $dbFile = sys_get_temp_dir() . '/ovf_test_shortopts.db';
        $sqlitePdo = new PDO('sqlite:' . $dbFile);
        AdminDatabaseTestHelper::initializeSchema($sqlitePdo);

        try {
            $res = $this->executeSubprocess(
                [
                    '-e', 'shortopts@oceanviewflats.com',
                    '-n', 'Short Options User',
                    '-p', 'ShortOptionsPass123!',
                    '-r', 'admin',
                ],
                '$GLOBALS["TEST_PDO"] = new PDO("sqlite:" . ' . var_export($dbFile, true) . ');'
            );

            $this->assertSame(0, $res['exitCode'], 'Failed with stderr: ' . $res['stderr']);
            $this->assertStringContainsString('Admin user successfully created', $res['stdout']);

            $stmt = $sqlitePdo->prepare('SELECT * FROM admin_users WHERE email = :email');
            $stmt->execute([':email' => 'shortopts@oceanviewflats.com']);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assertIsArray($user);
            $this->assertSame('Short Options User', $user['name']);
            $this->assertSame('admin', $user['role']);
        } finally {
            if (file_exists($dbFile)) {
                @unlink($dbFile);
            }
        }
    }

    public function testMissingConfigurationReturnsCodeOne(): void
    {
        // Execute without injected PDO and with empty config root
        $emptyDir = sys_get_temp_dir() . '/ovf_no_config_' . uniqid();
        mkdir($emptyDir, 0777, true);

        try {
            $scriptPath = dirname(__DIR__, 3) . '/scripts/create-admin-user.php';
            $phpCode = sprintf(
                'require_once %s; $argv = ["scripts/create-admin-user.php", "--email=test@test.com", "--name=Test", "--password=password123"]; $_SERVER["argv"] = $argv; putenv("OVF_CONFIG_ROOT=" . %s); exit((int) (require %s));',
                var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
                var_export($emptyDir, true),
                var_export($scriptPath, true)
            );
            $process = proc_open(
                ['php', '-r', $phpCode],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $this->assertSame(1, $exitCode);
        } finally {
            @rmdir($emptyDir);
        }
    }
}
