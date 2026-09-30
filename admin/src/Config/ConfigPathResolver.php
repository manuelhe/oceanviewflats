<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Config;

use RuntimeException;

/**
 * Resilient configuration path resolver for local development and cPanel production hosting.
 */
final class ConfigPathResolver
{
    /**
     * Resolves the config.php path by inspecting standard local and production paths.
     *
     * 1. Checks $rootDir . '/public/api/config.php' (local development)
     * 2. Checks $rootDir . '/public_html/api/config.php' (cPanel production)
     *
     * @param string $rootDir Repository root directory or cPanel home directory
     * @return string Absolute path to config.php
     * @throws RuntimeException If neither path exists
     */
    public static function resolveConfigPath(string $rootDir): string
    {
        $cleanRoot = rtrim($rootDir, '/\\');

        $localPath = $cleanRoot . '/public/api/config.php';
        if (file_exists($localPath)) {
            return $localPath;
        }

        $cpanelPath = $cleanRoot . '/public_html/api/config.php';
        if (file_exists($cpanelPath)) {
            return $cpanelPath;
        }

        throw new RuntimeException(
            "Configuration file not found. Checked: '{$localPath}' and '{$cpanelPath}'."
        );
    }


    /**
     * Resolves the path and loads the configuration array.
     *
     * @param string $rootDir
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public static function loadConfig(string $rootDir): array
    {
        $path = self::resolveConfigPath($rootDir);

        /** @var array<string, mixed> $config */
        $config = require $path;

        return $config;
    }
}
