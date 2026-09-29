<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Auth;

/**
 * Value object representing the outcome of an administrative authentication attempt.
 */
final class LoginResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
    public const STATUS_ACCOUNT_DISABLED = 'account_disabled';
    public const STATUS_ACCOUNT_LOCKED = 'account_locked';
    public const STATUS_RATE_LIMITED = 'rate_limited';

    /**
     * @param array<string, mixed>|null $user
     */
    private function __construct(
        private readonly string $status,
        private readonly ?array $user = null,
        private readonly int $lockoutMinutes = 0,
        private readonly int $retryAfterSeconds = 0
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function success(array $user): self
    {
        return new self(self::STATUS_SUCCESS, $user);
    }

    public static function invalidCredentials(): self
    {
        return new self(self::STATUS_INVALID_CREDENTIALS);
    }

    public static function accountDisabled(): self
    {
        return new self(self::STATUS_ACCOUNT_DISABLED);
    }

    public static function accountLocked(int $remainingMinutes): self
    {
        return new self(self::STATUS_ACCOUNT_LOCKED, null, $remainingMinutes);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(self::STATUS_RATE_LIMITED, null, 0, $retryAfterSeconds);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getLockoutMinutes(): int
    {
        return $this->lockoutMinutes;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
