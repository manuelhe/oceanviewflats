<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Auth;

use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\InMemoryIpRateLimiter;
use OceanViewFlats\Admin\Auth\LoginResult;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private PDO $pdo;
    private InMemoryIpRateLimiter $rateLimiter;
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "admin",
                is_active INTEGER NOT NULL DEFAULT 1,
                failed_login_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT DEFAULT NULL,
                last_login_at TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ');

        $this->rateLimiter = new InMemoryIpRateLimiter(maxAttempts: 10, windowSeconds: 900);
        $this->authService = new AuthService($this->pdo, $this->rateLimiter);
    }

    private function insertUser(
        string $email,
        string $password,
        int $isActive = 1,
        int $failedAttempts = 0,
        ?string $lockedUntil = null
    ): int {
        $hash = $this->authService->hashPassword($password);
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_users (email, password_hash, name, role, is_active, failed_login_attempts, locked_until)
            VALUES (:email, :hash, "Admin User", "admin", :active, :failed, :locked)
        ');
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'hash' => $hash,
            'active' => $isActive,
            'failed' => $failedAttempts,
            'locked' => $lockedUntil,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testSuccessfulAuthentication(): void
    {
        $userId = $this->insertUser('admin@oceanviewflats.com', 'SecureSecret123!');

        $result = $this->authService->authenticate('admin@oceanviewflats.com', 'SecureSecret123!', '192.168.1.10');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(LoginResult::STATUS_SUCCESS, $result->getStatus());
        $user = $result->getUser();
        $this->assertIsArray($user);
        $this->assertSame($userId, (int) $user['id']);
        $this->assertSame('admin@oceanviewflats.com', $user['email']);

        // Verify database state: failed_login_attempts = 0 and last_login_at is set
        $stmt = $this->pdo->prepare('SELECT failed_login_attempts, last_login_at, locked_until FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $this->assertSame(0, (int) $row['failed_login_attempts']);
        $this->assertNotNull($row['last_login_at']);
        $this->assertNull($row['locked_until']);
    }

    public function testEmailIsCaseAndSpaceInsensitive(): void
    {
        $userId = $this->insertUser('manager@oceanviewflats.com', 'Pass123456!');

        $result = $this->authService->authenticate('  MANAgER@OceanViewFlats.com ', 'Pass123456!', '10.0.0.1');

        $this->assertTrue($result->isSuccess());
        $user = $result->getUser();
        $this->assertIsArray($user);
        $this->assertSame($userId, (int) $user['id']);
    }

    public function testTimingAttackDefenseOnUnknownUser(): void
    {
        // User does not exist in DB
        $start = microtime(true);
        $result = $this->authService->authenticate('nonexistent@domain.com', 'WrongPass123!', '192.168.1.50');
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result->isSuccess());
        $this->assertSame(LoginResult::STATUS_INVALID_CREDENTIALS, $result->getStatus());
        // Verify constant-time dummy verification occurred (Argon2id calculation takes > 1ms)
        $this->assertGreaterThan(0.001, $elapsed);
    }

    public function testDisabledAccountCannotAuthenticate(): void
    {
        $this->insertUser('disabled@oceanviewflats.com', 'ValidPass123!', isActive: 0);

        $result = $this->authService->authenticate('disabled@oceanviewflats.com', 'ValidPass123!', '192.168.1.11');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(LoginResult::STATUS_ACCOUNT_DISABLED, $result->getStatus());
    }

    public function testLockedAccountCannotAuthenticate(): void
    {
        // Locked until 10 minutes in the future
        $futureTime = date('Y-m-d H:i:s', time() + 600);
        $this->insertUser('locked@oceanviewflats.com', 'ValidPass123!', isActive: 1, failedAttempts: 5, lockedUntil: $futureTime);

        $result = $this->authService->authenticate('locked@oceanviewflats.com', 'ValidPass123!', '192.168.1.12');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(LoginResult::STATUS_ACCOUNT_LOCKED, $result->getStatus());
        $this->assertGreaterThanOrEqual(9, $result->getLockoutMinutes());
        $this->assertLessThanOrEqual(11, $result->getLockoutMinutes());
    }

    public function testExpiredLockoutAllowsSuccessfulLogin(): void
    {
        // Lock expired 5 minutes ago
        $pastTime = date('Y-m-d H:i:s', time() - 300);
        $userId = $this->insertUser('expired-lock@oceanviewflats.com', 'ValidPass123!', isActive: 1, failedAttempts: 5, lockedUntil: $pastTime);

        $result = $this->authService->authenticate('expired-lock@oceanviewflats.com', 'ValidPass123!', '192.168.1.13');

        $this->assertTrue($result->isSuccess());

        // Verify lock and failed attempts cleared
        $stmt = $this->pdo->prepare('SELECT failed_login_attempts, locked_until FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $this->assertSame(0, (int) $row['failed_login_attempts']);
        $this->assertNull($row['locked_until']);
    }

    public function testFailedAttemptsIncrementAndProgressiveLockoutAtFive(): void
    {
        $userId = $this->insertUser('brute@oceanviewflats.com', 'CorrectPassword123!');

        // 4 failed attempts
        for ($i = 1; $i <= 4; $i++) {
            $result = $this->authService->authenticate('brute@oceanviewflats.com', 'WrongPassword', '192.168.1.20');
            $this->assertSame(LoginResult::STATUS_INVALID_CREDENTIALS, $result->getStatus());

            $stmt = $this->pdo->prepare('SELECT failed_login_attempts, locked_until FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();
            $this->assertSame($i, (int) $row['failed_login_attempts']);
            $this->assertNull($row['locked_until']);
        }

        // 5th failed attempt -> locks for 15 minutes
        $result5 = $this->authService->authenticate('brute@oceanviewflats.com', 'WrongPassword', '192.168.1.20');
        $this->assertSame(LoginResult::STATUS_INVALID_CREDENTIALS, $result5->getStatus());

        $stmt = $this->pdo->prepare('SELECT failed_login_attempts, locked_until FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row5 = $stmt->fetch();
        $this->assertSame(5, (int) $row5['failed_login_attempts']);
        $this->assertNotNull($row5['locked_until']);
        $lockedUntilEpoch = strtotime((string) $row5['locked_until']);
        $this->assertGreaterThanOrEqual(time() + 800, $lockedUntilEpoch);
        $this->assertLessThanOrEqual(time() + 950, $lockedUntilEpoch);

        // Immediate next attempt should report account locked
        $resultLocked = $this->authService->authenticate('brute@oceanviewflats.com', 'CorrectPassword123!', '192.168.1.20');
        $this->assertSame(LoginResult::STATUS_ACCOUNT_LOCKED, $resultLocked->getStatus());
        $this->assertGreaterThanOrEqual(14, $resultLocked->getLockoutMinutes());
    }

    public function testProgressiveLockoutAtTenFailuresLocksForSixtyMinutes(): void
    {
        // Setup user with 9 failures
        $userId = $this->insertUser('brute10@oceanviewflats.com', 'CorrectPassword123!', failedAttempts: 9);

        // 10th failure -> locks for 60 minutes
        $result = $this->authService->authenticate('brute10@oceanviewflats.com', 'WrongPassword', '192.168.1.21');
        $this->assertSame(LoginResult::STATUS_INVALID_CREDENTIALS, $result->getStatus());

        $stmt = $this->pdo->prepare('SELECT failed_login_attempts, locked_until FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        $this->assertSame(10, (int) $row['failed_login_attempts']);
        $lockedUntilEpoch = strtotime((string) $row['locked_until']);
        $this->assertGreaterThanOrEqual(time() + 3500, $lockedUntilEpoch);
        $this->assertLessThanOrEqual(time() + 3650, $lockedUntilEpoch);
    }

    public function testIpLevelRateLimitingBlocksExcessRequests(): void
    {
        $this->insertUser('admin@oceanviewflats.com', 'ValidPassword123!');
        $attackerIp = '203.0.113.99';

        // 10 failed login attempts from this IP
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->recordFailure($attackerIp);
        }

        // 11th request from attacker IP must be rejected before DB checks
        $result = $this->authService->authenticate('admin@oceanviewflats.com', 'ValidPassword123!', $attackerIp);
        $this->assertSame(LoginResult::STATUS_RATE_LIMITED, $result->getStatus());
        $this->assertGreaterThan(0, $result->getRetryAfterSeconds());
    }

    public function testTransparentRehashUpdatesDatabaseHash(): void
    {
        // Insert user with a legacy/weaker hash (e.g. bcrypt PASSWORD_BCRYPT)
        $legacyHash = password_hash('RehashSecret123!', PASSWORD_BCRYPT, ['cost' => 10]);
        $stmt = $this->pdo->prepare('
            INSERT INTO admin_users (email, password_hash, name, role, is_active)
            VALUES ("rehash@oceanviewflats.com", :hash, "Rehash User", "admin", 1)
        ');
        $stmt->execute(['hash' => $legacyHash]);
        $userId = (int) $this->pdo->lastInsertId();

        // Authenticate - should succeed AND transparently upgrade hash to Argon2id
        $result = $this->authService->authenticate('rehash@oceanviewflats.com', 'RehashSecret123!', '192.168.1.30');
        $this->assertTrue($result->isSuccess());

        $stmt = $this->pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $newHash = (string) $stmt->fetchColumn();

        $this->assertNotSame($legacyHash, $newHash);
        $info = password_get_info($newHash);
        $this->assertSame(PASSWORD_ARGON2ID, $info['algo']);
    }
}
