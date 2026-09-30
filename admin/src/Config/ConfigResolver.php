<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Config;

use RuntimeException;

/**
 * Resilient configuration resolver for local development and cPanel production hosting.
 */
final class ConfigResolver
{
    /**
     * Resolves the config.php path by inspecting standard local and production paths.
     *
     * @param string $rootDir
     * @return string
     * @throws RuntimeException
     */
    public static function resolveConfigPath(string $rootDir): string
    {
        return ConfigPathResolver::resolveConfigPath($rootDir);
    }

    /**
     * Alias for resolveConfigPath.
     *
     * @param string $rootDir
     * @return string
     * @throws RuntimeException
     */
    public static function resolvePath(string $rootDir): string
    {
        return ConfigPathResolver::resolveConfigPath($rootDir);
    }

    /**
     * Alias for resolveConfigPath.
     *
     * @param string $rootDir
     * @return string
     * @throws RuntimeException
     */
    public static function resolve(string $rootDir): string
    {
        return ConfigPathResolver::resolveConfigPath($rootDir);
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
        return ConfigPathResolver::loadConfig($rootDir);
    }
}
