<?php
/**
 * OceanViewFlats Database Migration Runner
 * PHP 8.3 Compatible
 * 
 * Centralizes all schema creation, columns adjustments, and database bootstrapping.
 * Can be run from the terminal: php scripts/migrate.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use OceanViewFlats\Admin\Config\ConfigPathResolver;
use OceanViewFlats\Domain\Database\MigrationRunner;
use OceanViewFlats\Domain\Support\EnvLoader;

echo "=== OceanViewFlats Database Migration Running ===\n";

// 1. Discover environment variables from .htaccess or .env
$root = dirname(__DIR__);
EnvLoader::load($root);

// 2. Load database configuration
$configPath = ConfigPathResolver::resolveConfigPath($root);
$config = require $configPath;

$dbConfig = $config['db'] ?? [];
$dbName = (string) ($dbConfig['dbname'] ?? 'oceanviewflats_db');
$dbHost = (string) ($dbConfig['host'] ?? '127.0.0.1');

try {
    echo "Connecting to MySQL server on {$dbHost} (database: {$dbName})...\n";
    $pdo = MigrationRunner::connect($dbConfig);
    echo "Connected successfully.\n";

    echo "Running schema migrations...\n";
    $logs = MigrationRunner::run($pdo, $dbName);
    foreach ($logs as $log) {
        echo $log . "\n";
    }

    echo "=== Database Migrations Completed Successfully! ===\n";
} catch (PDOException $e) {
    echo "Database Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
