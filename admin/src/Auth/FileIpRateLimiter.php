<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Auth;

/**
 * File-backed IP rate limiter providing persistence across requests with atomic file locking.
 */
final class FileIpRateLimiter implements IpRateLimiterInterface
{
    public const DEFAULT_MAX_ATTEMPTS = 10;
    public const DEFAULT_WINDOW_SECONDS = 900; // 15 minutes

    public function __construct(
        private readonly string $storageFilePath,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS
    ) {
    }

    public static function createDefault(): self
    {
        $path = sys_get_temp_dir() . '/ovf_admin_ip_rate_limits.json';
        return new self($path);
    }

    public function isAllowed(string $ip): bool
    {
        $data = $this->readData();
        $attempts = $this->pruneAttempts($data[$ip] ?? []);
        return count($attempts) < $this->maxAttempts;
    }

    public function recordFailure(string $ip): void
    {
        $this->mutate(function (array &$data) use ($ip): void {
            $attempts = $this->pruneAttempts($data[$ip] ?? []);
            $attempts[] = time();
            $data[$ip] = $attempts;
        });
    }

    public function getRetryAfter(string $ip): int
    {
        $data = $this->readData();
        $attempts = $this->pruneAttempts($data[$ip] ?? []);
        if (empty($attempts) || count($attempts) < $this->maxAttempts) {
            return 0;
        }

        /** @var non-empty-array<int, int> $attempts */
        $oldestAttempt = min($attempts);
        $retryAfter = ($oldestAttempt + $this->windowSeconds) - time();

        return max(0, $retryAfter);
    }

    public function reset(string $ip): void
    {
        $this->mutate(function (array &$data) use ($ip): void {
            unset($data[$ip]);
        });
    }

    /**
     * @param array<int, int> $attempts
     * @return array<int, int>
     */
    private function pruneAttempts(array $attempts): array
    {
        $cutoff = time() - $this->windowSeconds;
        return array_values(array_filter($attempts, static fn (int $t): bool => $t > $cutoff));
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function readData(): array
    {
        if (!file_exists($this->storageFilePath)) {
            return [];
        }

        $content = @file_get_contents($this->storageFilePath);
        if ($content === false || $content === '') {
            return [];
        }

        /** @var array<string, array<int, int>>|null $decoded */
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param callable(array<string, array<int, int>>&): void $mutator
     */
    private function mutate(callable $mutator): void
    {
        $dir = dirname($this->storageFilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($this->storageFilePath, 'c+');
        if ($fp === false) {
            return;
        }

        if (flock($fp, LOCK_EX)) {
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }

            /** @var array<string, array<int, int>> $data */
            $data = [];
            if ($content !== '') {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            // Prune all expired entries across all IPs
            $cutoff = time() - $this->windowSeconds;
            foreach ($data as $ipKey => $attempts) {
                $valid = array_values(array_filter($attempts, static fn (int $t): bool => $t > $cutoff));
                if (empty($valid)) {
                    unset($data[$ipKey]);
                } else {
                    $data[$ipKey] = $valid;
                }
            }

            $mutator($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($data, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        }

        fclose($fp);
    }
}
