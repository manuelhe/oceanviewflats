<?php
/**
 * OceanViewFlats Database Migration Runner
 * PHP 8 Compatible
 * 
 * Centralizes all schema creation, columns adjustments, and database bootstrapping.
 * Can be run from the terminal: php scripts/migrate.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

echo "=== OceanViewFlats Database Migration Running ===\n";

// 1. Load config
$configPath = dirname(__DIR__) . '/public/api/config.php';
if (!file_exists($configPath)) {
    exit("Error: Configuration file not found at $configPath\n");
}
$config = require $configPath;

$dbHost = $config['db']['host'] ?? '127.0.0.1';
$dbName = $config['db']['dbname'] ?? 'oceanviewflats_db';
$dbUser = $config['db']['user'] ?? 'root';
$dbPass = $config['db']['pass'] ?? '';

try {
    // 2. Connect to MySQL without database selected to bootstrap if needed
    $dsn = "mysql:host=$dbHost;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    echo "Connected to MySQL server on $dbHost\n";

    // 3. Create Database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    echo "Database `$dbName` selected/created.\n";

    // 4. Create reservations table if missing
    $reservationsTableSql = "
        CREATE TABLE IF NOT EXISTS `reservations` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `reservation_uid` VARCHAR(36) NOT NULL UNIQUE,
          `property_id` VARCHAR(10) NOT NULL,
          `guest_name` VARCHAR(120) NOT NULL,
          `guest_email` VARCHAR(100) NOT NULL,
          `guest_phone` VARCHAR(25) NOT NULL,
          `check_in` DATE NOT NULL,
          `check_out` DATE NOT NULL,
          `total_price` DECIMAL(10, 2) NOT NULL,
          `mercadopago_preference_id` VARCHAR(255) DEFAULT NULL,
          `mercadopago_payment_id` VARCHAR(255) DEFAULT NULL,
          `payment_status` VARCHAR(50) DEFAULT NULL,
          `payment_method_id` VARCHAR(50) DEFAULT NULL,
          `payment_detail` TEXT DEFAULT NULL,
          `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
          `lang` VARCHAR(5) NOT NULL DEFAULT 'en',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
          INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($reservationsTableSql);
    echo "Table `reservations` verified/created.\n";

    // 5. Upgrade existing table columns (Self-healing columns)
    $columns = [
        'mercadopago_payment_id' => 'VARCHAR(255) DEFAULT NULL',
        'payment_status' => 'VARCHAR(50) DEFAULT NULL',
        'payment_method_id' => 'VARCHAR(50) DEFAULT NULL',
        'payment_detail' => 'TEXT DEFAULT NULL',
        'lang' => "VARCHAR(5) NOT NULL DEFAULT 'en'"
    ];

    foreach ($columns as $col => $type) {
        // Check if column exists
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = :dbname 
              AND TABLE_NAME = 'reservations' 
              AND COLUMN_NAME = :col
        ");
        $stmt->execute(['dbname' => $dbName, 'col' => $col]);
        $exists = $stmt->fetch();

        if (!$exists) {
            echo "Upgrading reservations schema: Adding column `$col`...\n";
            $pdo->exec("ALTER TABLE `reservations` ADD COLUMN `$col` $type");
        }
    }

    // 6. Create payment_idempotency table if missing
    $idempotencyTableSql = "
        CREATE TABLE IF NOT EXISTS `payment_idempotency` (
          `idempotency_key` VARCHAR(100) NOT NULL PRIMARY KEY,
          `payment_id` VARCHAR(100) NOT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($idempotencyTableSql);
    echo "Table `payment_idempotency` verified/created.\n";

    echo "=== Database Migrations Completed Successfully! ===\n";

} catch (PDOException $e) {
    echo "Database Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
