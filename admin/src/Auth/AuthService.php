<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Auth;

use PDO;

/**
 * Service orchestrating admin authentication, Argon2id verification, timing-attack defense,
 * progressive lockout rate limiting, and transparent password rehashing.
 */
final class AuthService
{
    /**
     * Recommended Argon2id cost parameters per ADR 0005 & Spec.
     */
    public const DEFAULT_ARGON2_OPTIONS = [
        'memory_cost' => 65536, // 64 MB
        'time_cost' => 4,       // 4 iterations
        'threads' => 2,         // 2 parallel threads
    ];

    public const DEFAULT_DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=2$UzY4amJ3NUlMUzhMU1guOA$RRauyafdI692Zo6wI0n+DUxzmQKfNx+71ip9H6hnc/o';

    /**
     * @var array<string, int>
     */
    private array $argonOptions;

    private string $dummyHash;

    /**
     * @param array<string, int> $argonOptions
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?IpRateLimiterInterface $ipRateLimiter = null,
        array $argonOptions = self::DEFAULT_ARGON2_OPTIONS,
        ?string $dummyHash = null
    ) {
        $this->argonOptions = $argonOptions;
        $this->dummyHash = $dummyHash ?? self::DEFAULT_DUMMY_HASH;
    }

    /**
     * Attempts authentication with timing-attack and brute-force defenses.
     */
    public function authenticate(string $email, string $password, string $ipAddress = ''): LoginResult
    {
        // 1. IP-level velocity defense
        if ($ipAddress !== '' && $this->ipRateLimiter !== null && !$this->ipRateLimiter->isAllowed($ipAddress)) {
            $retryAfter = $this->ipRateLimiter->getRetryAfter($ipAddress);
            return LoginResult::rateLimited($retryAfter);
        }

        $normalizedEmail = strtolower(trim($email));

        // 2. Fetch user by email
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $normalizedEmail]);
        /** @var array<string, mixed>|false $user */
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user === false) {
            // Constant-time dummy verification to thwart user enumeration timing attacks
            password_verify($password, $this->getDummyHash());
            if ($ipAddress !== '' && $this->ipRateLimiter !== null) {
                $this->ipRateLimiter->recordFailure($ipAddress);
            }
            return LoginResult::invalidCredentials();
        }

        // 3. Account active check
        if ((int) $user['is_active'] !== 1) {
            return LoginResult::accountDisabled();
        }

        // 4. Lockout check
        if ($user['locked_until'] !== null) {
            $lockedUntilEpoch = strtotime((string) $user['locked_until']);
            if ($lockedUntilEpoch > time()) {
                $remainingMinutes = (int) ceil(($lockedUntilEpoch - time()) / 60);
                return LoginResult::accountLocked($remainingMinutes);
            }
        }

        // 5. Password verification
        $passwordHash = (string) $user['password_hash'];
        if (!password_verify($password, $passwordHash)) {
            $this->recordFailedAttempt((int) $user['id'], (int) $user['failed_login_attempts']);
            if ($ipAddress !== '' && $this->ipRateLimiter !== null) {
                $this->ipRateLimiter->recordFailure($ipAddress);
            }
            return LoginResult::invalidCredentials();
        }

        // 6. Transparent rehash check
        if (password_needs_rehash($passwordHash, PASSWORD_ARGON2ID, $this->argonOptions)) {
            $newHash = $this->hashPassword($password);
            $updateStmt = $this->pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
            $updateStmt->execute(['hash' => $newHash, 'id' => (int) $user['id']]);
            $user['password_hash'] = $newHash;
        }

        // 7. Successful login state update
        $now = date('Y-m-d H:i:s');
        $resetStmt = $this->pdo->prepare('
            UPDATE admin_users 
            SET failed_login_attempts = 0, locked_until = NULL, last_login_at = :last_login
            WHERE id = :id
        ');
        $resetStmt->execute([
            'last_login' => $now,
            'id' => (int) $user['id'],
        ]);

        if ($ipAddress !== '' && $this->ipRateLimiter !== null) {
            $this->ipRateLimiter->reset($ipAddress);
        }

        $user['failed_login_attempts'] = 0;
        $user['locked_until'] = null;
        $user['last_login_at'] = $now;

        return LoginResult::success($user);
    }

    /**
     * Generates an Argon2id password hash with tuned cost parameters.
     */
    public function hashPassword(string $plaintextPassword): string
    {
        return password_hash($plaintextPassword, PASSWORD_ARGON2ID, $this->argonOptions);
    }

    private function recordFailedAttempt(int $userId, int $currentFailedAttempts): void
    {
        $newAttempts = $currentFailedAttempts + 1;
        $lockedUntil = null;

        if ($newAttempts >= 10) {
            // 60 minutes lockout
            $lockedUntil = date('Y-m-d H:i:s', time() + 3600);
        } elseif ($newAttempts >= 5) {
            // 15 minutes lockout
            $lockedUntil = date('Y-m-d H:i:s', time() + 900);
        }

        $stmt = $this->pdo->prepare('
            UPDATE admin_users 
            SET failed_login_attempts = :attempts, locked_until = :locked 
            WHERE id = :id
        ');
        $stmt->execute([
            'attempts' => $newAttempts,
            'locked' => $lockedUntil,
            'id' => $userId,
        ]);
    }

    private function getDummyHash(): string
    {
        return $this->dummyHash;
    }
}
