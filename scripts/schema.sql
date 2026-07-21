-- =============================================================================
-- OceanViewFlats Database Setup and Migration Schema
-- 
-- This script initializes the database and table structure for direct booking.
-- Execute this on your remote MySQL server to complete the manual setup.
-- =============================================================================

-- 1. Create Database
CREATE DATABASE IF NOT EXISTS `oceanviewflats_db` 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `oceanviewflats_db`;

-- 2. Create Reservations Log Table
-- Declares InnoDB engine to support row-level locking on date ranges
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
  -- Checkout Pro preference tracking
  `mercadopago_preference_id` VARCHAR(255) DEFAULT NULL,
  -- Checkout Bricks (Custom API) payment tracking
  `mercadopago_payment_id` VARCHAR(255) DEFAULT NULL,
  `payment_status` VARCHAR(50) DEFAULT NULL,
  `payment_method_id` VARCHAR(50) DEFAULT NULL,
  `payment_detail` TEXT DEFAULT NULL,
  `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
  `lang` VARCHAR(5) NOT NULL DEFAULT 'en',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  -- Optimized indexes for calendar availability checks and status updates
  INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create Payment Idempotency Log Table
-- Double-charge prevention for API integrations
CREATE TABLE IF NOT EXISTS `payment_idempotency` (
  `idempotency_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `payment_id` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verification Query: SHOW TABLES;
-- =============================================================================
