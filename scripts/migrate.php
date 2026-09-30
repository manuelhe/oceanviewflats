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

echo "=== OceanViewFlats Database Migration Running ===\n";

// 1. Load config
$root = dirname(__DIR__);
$configPath = ConfigPathResolver::resolveConfigPath($root);
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
          `refunded_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
          `source` VARCHAR(30) NOT NULL DEFAULT 'web',
          `mercadopago_preference_id` VARCHAR(255) DEFAULT NULL,
          `mercadopago_payment_id` VARCHAR(255) DEFAULT NULL,
          `payment_status` VARCHAR(50) DEFAULT NULL,
          `payment_method_id` VARCHAR(50) DEFAULT NULL,
          `payment_detail` TEXT DEFAULT NULL,
          `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
          `lang` VARCHAR(5) NOT NULL DEFAULT 'en',
          `registry_completed` TINYINT(1) NOT NULL DEFAULT 0,
          `registry_completed_at` DATETIME DEFAULT NULL,
          `door_code` VARCHAR(20) DEFAULT NULL,
          `notes` TEXT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
          INDEX `idx_status` (`status`),
          INDEX `idx_registry_completed` (`registry_completed`),
          INDEX `idx_source` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($reservationsTableSql);
    echo "Table `reservations` verified/created.\n";

    // 5. Upgrade existing table columns (Self-healing columns)
    $reservationColumns = [
        'mercadopago_payment_id' => 'VARCHAR(255) DEFAULT NULL',
        'payment_status' => 'VARCHAR(50) DEFAULT NULL',
        'payment_method_id' => 'VARCHAR(50) DEFAULT NULL',
        'payment_detail' => 'TEXT DEFAULT NULL',
        'lang' => "VARCHAR(5) NOT NULL DEFAULT 'en'",
        'registry_completed' => "TINYINT(1) NOT NULL DEFAULT 0",
        'registry_completed_at' => "DATETIME DEFAULT NULL",
        'door_code' => "VARCHAR(20) DEFAULT NULL",
        'refunded_amount' => "DECIMAL(10, 2) NOT NULL DEFAULT 0.00",
        'source' => "VARCHAR(30) NOT NULL DEFAULT 'web'",
        'notes' => "TEXT DEFAULT NULL"
    ];

    foreach ($reservationColumns as $col => $type) {
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

    // 7. Create guest_registries table if missing (ADR 0001 compliance)
    $registriesTableSql = "
        CREATE TABLE IF NOT EXISTS `guest_registries` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `reservation_uid` VARCHAR(36) NOT NULL,
          `property_id` VARCHAR(10) NOT NULL,
          `check_in` DATE NOT NULL,
          `check_out` DATE NOT NULL,
          `guest_count` INT UNSIGNED NOT NULL DEFAULT 1,
          `guests_payload` JSON NOT NULL,
          `car_plates` VARCHAR(20) DEFAULT NULL,
          `car_model` VARCHAR(100) DEFAULT NULL,
          `ip_address` VARCHAR(45) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_reg_reservation` (`reservation_uid`),
          INDEX `idx_reg_property_dates` (`property_id`, `check_in`, `check_out`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($registriesTableSql);
    echo "Table `guest_registries` verified/created.\n";

    // 8. Create admin_users table if missing (ADR 0005 compliance)
    $adminUsersTableSql = "
        CREATE TABLE IF NOT EXISTS `admin_users` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `email` VARCHAR(120) NOT NULL UNIQUE,
          `password_hash` VARCHAR(255) NOT NULL,
          `name` VARCHAR(100) NOT NULL,
          `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
          `is_active` TINYINT(1) NOT NULL DEFAULT 1,
          `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
          `locked_until` DATETIME DEFAULT NULL,
          `last_login_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_admin_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($adminUsersTableSql);
    echo "Table `admin_users` verified/created.\n";

    // 9. Create admin_audit_logs table if missing (ADR 0005 compliance)
    $auditLogsTableSql = "
        CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `admin_user_id` INT UNSIGNED DEFAULT NULL,
          `action` VARCHAR(50) NOT NULL,
          `entity_type` VARCHAR(30) NOT NULL,
          `entity_id` VARCHAR(50) NOT NULL,
          `payload_before` JSON DEFAULT NULL,
          `payload_after` JSON DEFAULT NULL,
          `ip_address` VARCHAR(45) NOT NULL,
          `user_agent` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
          INDEX `idx_audit_admin` (`admin_user_id`),
          INDEX `idx_audit_action` (`action`),
          INDEX `idx_audit_created` (`created_at`),
          CONSTRAINT `fk_audit_admin_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($auditLogsTableSql);
    echo "Table `admin_audit_logs` verified/created.\n";

    // 10. Create calendar_blocks table if missing
    $calendarBlocksTableSql = "
        CREATE TABLE IF NOT EXISTS `calendar_blocks` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `property_id` VARCHAR(10) NOT NULL,
          `start_date` DATE NOT NULL,
          `end_date` DATE NOT NULL,
          `reason` VARCHAR(255) NOT NULL,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_cb_property_dates` (`property_id`, `start_date`, `end_date`),
          INDEX `idx_cb_created_by` (`created_by`),
          CONSTRAINT `fk_cb_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($calendarBlocksTableSql);
    echo "Table `calendar_blocks` verified/created.\n";

    // 11. Create property_rates table if missing
    $propertyRatesTableSql = "
        CREATE TABLE IF NOT EXISTS `property_rates` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `property_id` VARCHAR(10) NOT NULL,
          `start_date` DATE NOT NULL,
          `end_date` DATE NOT NULL,
          `season_name` VARCHAR(100) NOT NULL,
          `price_per_night` DECIMAL(10, 2) NOT NULL,
          `min_stay` INT UNSIGNED NOT NULL DEFAULT 2,
          `cleaning_fee` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
          `resort_fee` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
          `created_by` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX `idx_pr_property_dates` (`property_id`, `start_date`, `end_date`),
          INDEX `idx_pr_property` (`property_id`),
          CONSTRAINT `fk_pr_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($propertyRatesTableSql);
    echo "Table `property_rates` verified/created.\n";

    // 12. Create reservation_refunds table if missing (Mercado Pago Gateway Reconciliation)
    $reservationRefundsTableSql = "
        CREATE TABLE IF NOT EXISTS `reservation_refunds` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `reservation_uid` VARCHAR(36) NOT NULL,
          `mercadopago_refund_id` VARCHAR(100) DEFAULT NULL UNIQUE,
          `mercadopago_payment_id` VARCHAR(100) NOT NULL,
          `amount` DECIMAL(10, 2) NOT NULL,
          `status` VARCHAR(30) NOT NULL DEFAULT 'approved',
          `reason` VARCHAR(255) DEFAULT NULL,
          `source` VARCHAR(30) NOT NULL DEFAULT 'admin',
          `admin_user_id` INT UNSIGNED DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_ref_res_uid` (`reservation_uid`),
          INDEX `idx_ref_payment_id` (`mercadopago_payment_id`),
          INDEX `idx_ref_admin` (`admin_user_id`),
          CONSTRAINT `fk_ref_admin_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($reservationRefundsTableSql);
    echo "Table `reservation_refunds` verified/created.\n";

    echo "=== Database Migrations Completed Successfully! ===\n";

} catch (PDOException $e) {
    echo "Database Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
