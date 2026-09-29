<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Auth;

/**
 * In-memory IP rate limiter implementation for isolated testing.
 */
final class InMemoryIpRateLimiter implements IpRateLimiterInterface
{
    public const DEFAULT_MAX_ATTEMPTS = 10;
    public const DEFAULT_WINDOW_SECONDS = 900; // 15 minutes

    /**
     * @var array<string, array<int, int>> Map of IP => array of failure epoch timestamps
     */
    private array $attempts = [];

    public function __construct(
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS
    ) {
    }

    public function isAllowed(string $ip): bool
    {
        $this->pruneExpired($ip);
        return count($this->attempts[$ip] ?? []) < $this->maxAttempts;
    }

    public function recordFailure(string $ip, ?int $timestamp = null): void
    {
        $this->pruneExpired($ip);
        $time = $timestamp ?? time();
        $this->attempts[$ip][] = $time;
    }

    public function getRetryAfter(string $ip, ?int $now = null): int
    {
        $this->pruneExpired($ip);
        $attempts = $this->attempts[$ip] ?? [];
        if (empty($attempts) || count($attempts) < $this->maxAttempts) {
            return 0;
        }

        /** @var non-empty-array<int, int> $attempts */
        $oldestAttempt = min($attempts);
        $currentTime = $now ?? time();
        $retryAfter = ($oldestAttempt + $this->windowSeconds) - $currentTime;

        return max(0, $retryAfter);
    }

    public function reset(string $ip): void
    {
        unset($this->attempts[$ip]);
    }

    private function pruneExpired(string $ip): void
    {
        if (!isset($this->attempts[$ip])) {
            return;
        }

        $cutoff = time() - $this->windowSeconds;
        $this->attempts[$ip] = array_values(
            array_filter($this->attempts[$ip], static fn (int $t): bool => $t > $cutoff)
        );

        if (empty($this->attempts[$ip])) {
            unset($this->attempts[$ip]);
        }
    }
}
