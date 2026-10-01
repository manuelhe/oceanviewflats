<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Support;

/**
 * Resilient environment variable loader for CLI, cron, and web runtimes.
 *
 * In cPanel hosting (Pattern A), deployment secrets are injected as Apache SetEnv
 * directives in .htaccess files. When scripts run via CLI or cron jobs, Apache is bypassed
 * and native getenv()/$_ENV are empty. This loader discovers and parses .htaccess and .env
 * files to populate missing environment variables while respecting existing overrides.
 */
final class EnvLoader
{
    /**
     * Loads environment variables from discovered .htaccess or .env files.
     *
     * @param string|null $baseDir Base directory (defaults to repo root / cPanel home)
     * @return array<string, string> Map of newly populated variables
     */
    public static function load(?string $baseDir = null): array
    {
        $cleanRoot = rtrim($baseDir ?? (getenv('OVF_CONFIG_ROOT') ?: dirname(__DIR__, 3)), '/\\');

        $candidates = [
            $cleanRoot . '/admin/public/.htaccess',
            $cleanRoot . '/public_html/.htaccess',
            $cleanRoot . '/public/.htaccess',
            $cleanRoot . '/.htaccess',
            $cleanRoot . '/.env',
            $cleanRoot . '/admin/.env',
        ];

        $discovered = [];
        foreach ($candidates as $filePath) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $content = (string) file_get_contents($filePath);
            $parsed = str_ends_with($filePath, '.htaccess')
                ? self::parseHtaccess($content)
                : self::parseEnv($content);

            foreach ($parsed as $key => $val) {
                if (!array_key_exists($key, $discovered)) {
                    $discovered[$key] = $val;
                }
            }
        }

        $loaded = [];
        foreach ($discovered as $key => $val) {
            $hasEnv = isset($_ENV[$key]) && $_ENV[$key] !== '';
            $hasServer = isset($_SERVER[$key]) && $_SERVER[$key] !== '';
            $hasGetEnv = getenv($key) !== false && getenv($key) !== '';

            if (!$hasEnv && !$hasServer && !$hasGetEnv) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
                $loaded[$key] = $val;
            }
        }

        return $loaded;
    }

    /**
     * Parses Apache SetEnv directives from .htaccess file contents.
     *
     * @param string $content Raw .htaccess content
     * @return array<string, string>
     */
    public static function parseHtaccess(string $content): array
    {
        $vars = [];
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^SetEnv\s+([A-Za-z0-9_]+)\s+(.*)$/i', $trimmed, $matches)) {
                $key = $matches[1];
                $val = trim($matches[2]);
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                $vars[$key] = $val;
            }
        }

        return $vars;
    }

    /**
     * Parses standard KEY=VALUE lines from .env file contents.
     *
     * @param string $content Raw .env content
     * @return array<string, string>
     */
    public static function parseEnv(string $content): array
    {
        $vars = [];
        $lines = preg_split("/\r\n|\n|\r/", $content);
        if ($lines === false) {
            return $vars;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^([A-Za-z0-9_]+)\s*=\s*(.*)$/', $trimmed, $matches)) {
                $key = $matches[1];
                $val = trim($matches[2]);
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                $vars[$key] = $val;
            }
        }

        return $vars;
    }
}
