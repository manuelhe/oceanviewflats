<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Db;

use PDO;

/**
 * Factory creating resilient PDO connections for the Admin front-controller.
 */
final class DatabaseFactory
{
    /**
     * @param array{host?: string, dbname?: string, user?: string, pass?: string} $config
     */
    public static function createConnection(array $config): PDO
    {
        $host = $config['host'] ?? '127.0.0.1';
        $dbname = $config['dbname'] ?? 'oceanviewflats_db';
        $user = $config['user'] ?? 'root';
        $pass = $config['pass'] ?? '';

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
