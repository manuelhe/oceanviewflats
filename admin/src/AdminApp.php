<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin;

/**
 * OceanViewFlats Admin Application kernel baseline.
 */
final class AdminApp
{
    public const VERSION = '1.0.0';

    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
