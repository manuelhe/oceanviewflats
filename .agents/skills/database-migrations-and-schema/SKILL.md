---
name: database-migrations-and-schema
description: Best practices for managing SQL relational schemas, table optimizations, double-charge idempotency keys, and CLI migrations.
---

# Database Schema & Migration Standards

You are an expert backend database and infrastructure engineer. This guide defines standard rules for managing database tables, indexes, and execution schemas across development, staging, and remote host deployments.

---

## 🏗️ Centralized CLI Migrations (No Inline Altering)

Bypassing schema managers and running schema updates (`ALTER TABLE`, `CREATE TABLE`) during live request loops is **strictly forbidden**.
*   **Why?**: Inline updates introduce critical thread locks under traffic, degrade response times, expose DB credentials to unnecessary privileges, and cause structural fragmentation.
*   **The Standard**: All structural tables, indices, or column expansions are declared under [`scripts/schema.sql`](scripts/schema.sql) and executed dynamically using the self-healing migration CLI runner [`scripts/migrate.php`](scripts/migrate.php).

---

## 🛠️ Table Specifications & Indexes

Always design relational models utilizing the highly-compatible InnoDB storage engine with UTF-8 character encoding configurations:

```sql
CREATE TABLE IF NOT EXISTS `reservations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reservation_uid` VARCHAR(36) NOT NULL UNIQUE,
  ...
  `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
  INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 🗝️ Indexing Requirements:
*   Always attach compound indexes to date-lookup and availability-check vectors (e.g. `idx_property_dates`).
*   Always assign single indexes to active status filter columns (e.g. `idx_status`) to optimize calendar overlaps parsing.

---

## 🔒 Double-Charge Prevention (Idempotency Engine)

To prevent duplicate processing of PSE transfer webhooks, multi-click form submissions, or network retries, the schema supports a strict `payment_idempotency` log:

```sql
CREATE TABLE IF NOT EXISTS `payment_idempotency` (
  `idempotency_key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `payment_id` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

*   **Flow**: Prior to creating payments, query `payment_idempotency` using the `X-Idempotency-Key` or `reservation_uid` string.
*   **Action**: If a match is found, immediately short-circuit execution and return the previous transaction response payload, completely eliminating double-charging vectors.

---

## 🚀 Running Migrations

To apply database alterations during deployment:
1.  Add new fields, indices, or table structures to [`scripts/schema.sql`](scripts/schema.sql).
2.  Update the self-healing loop in [`scripts/migrate.php`](scripts/migrate.php) to conditionally add new items.
3.  Log into your hosting server terminal and execute:
    ```bash
    php scripts/migrate.php
    ```
