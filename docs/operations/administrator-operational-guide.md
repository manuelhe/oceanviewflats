# OceanViewFlats - Administrator Operational & Breaking Changes Guide
**Target Audience**: Application Administrators, Systems Engineers, Operations & Support Staff  
**Architecture Version**: Post-Settlement Clean Architecture & ADR 0001/0004 Gating (Issues #9, #10, #11)  
**Date**: September 2026  

---

## 1. Executive Summary

Recent architectural refactorings have transitioned the OceanViewFlats platform from loosely coupled, client-authoritative scripts into a domain-driven, server-authoritative architecture. The primary operational objectives are:
1. **Statutory Hospitality & Security Compliance ([ADR 0001](file:///Users/manuel.herrera/Projects/17071606/docs/adr/0001-mandatory-registry-before-access.md))**: Complete withholding of door keypad PINs and Wi-Fi credentials until the primary guest submits the official Colombian Guest Registry.
2. **Elimination of Plaintext Credentials**: Complete eradication of sensitive access credentials from URL GET query parameters, browser histories, and pre-rendered static artifacts.
3. **Server-Authoritative Pricing ([ADR 0004](file:///Users/manuel.herrera/Projects/17071606/docs/adr/0004-authoritative-quote-engine-and-itemized-pricing.md))**: Centralizing night-by-night seasonal pricing, cleaning fees, and lobby fees within an authoritative backend quote engine to eliminate client-side pricing drift.
4. **Decoupled Fulfillment Infrastructure**: Isolating email rendering, mail delivery, and Google Spreadsheet webhooks behind domain interfaces (Ports & Adapters) to ensure atomic database state and zero transactional crashes.

---

## 2. Summary of Breaking Changes

| Area | Prior Behavior (Legacy) | New Behavior (Current) | Operational Impact |
| :--- | :--- | :--- | :--- |
| **Guest Guide URLs** | `/guide/?doorCode=1606#&wifi=...` displayed credentials directly from query string. | Query parameters `doorCode`, `door_code`, and `wifi` are **completely ignored**. The guide only accepts `?code={reservation_uid}` and queries `/api/guide-access.php`. | **CRITICAL**: Any manual message or CRM template sending `doorCode` in links will leave the guest with locked credentials (`••••••`). Templates must be updated. |
| **Confirmation Emails** | Direct booking receipts included door PINs and Wi-Fi passwords immediately upon payment. | Confirmation emails **strictly omit** door codes, Wi-Fi passwords, and direct guide URLs. They contain an invitation button to `/registry/?code=...`. | Guests must complete the registration form before they receive access credentials. Support staff must not manually hand out PINs without registry submission. |
| **Database Schema** | `reservations` table lacked registration status tracking. | `reservations` has `registry_completed` (TINYINT) and `registry_completed_at` (DATETIME), plus a new `guest_registries` log table. | **MANDATORY**: Administrator must run `php scripts/migrate.php` on production. Omission causes SQL fatal errors on registry and access endpoints. |
| **Credential Storage** | Plaintext codes were embedded in client-side HTML templates or JS variables. | Credentials are exclusively stored on the server in `public/api/config.php` and loaded dynamically via environment variables (`PROPERTY_1606_DOOR_CODE`, etc.). | Door PIN changes no longer require building/deploying static assets (`npm run build`). Updating environment variables or config updates credentials live. |
| **Direct Quote Calculation** | Client JavaScript calculated subtotals from static JSON; backend verified superficial totals. | Backend `SeasonalPricer` authoritatively computes night-by-night rates, minimum stays, and fees from `public/data/prices.csv`. Client totals are ignored. | Any change in property nightly pricing or minimum-stay tiers must be made in `public/data/prices.csv`. |

---

## 3. Deployment & Mandatory Migration Routine

### 3.1 Migration Command
During deployment, the administrator **must** execute the CLI migration routine:

```bash
php scripts/migrate.php
```

### 3.2 Schema Additions Verified by `scripts/migrate.php`
1. **`reservations` Table**:
   - `registry_completed`: `TINYINT(1) NOT NULL DEFAULT 0`
   - `registry_completed_at`: `DATETIME DEFAULT NULL`
   - Index: `idx_registry_completed`
2. **`guest_registries` Table (New)**:
   - Stores full legal guest submissions (names, ID types, document numbers, ages, vehicle plates, vehicle model, IP address, and timestamp).
   - Indexed on `reservation_uid` and `(property_id, check_in, check_out)`.

### 3.3 Emergency SQL (Manual Execution Fallback)
If the migration script cannot be run via CLI due to restricted hosting environments (e.g. cPanel without SSH access), run the following SQL queries directly in phpMyAdmin or the MySQL shell:

```sql
USE `oceanviewflats_db`;

-- 1. Add registry tracking columns to reservations table if missing
ALTER TABLE `reservations` 
  ADD COLUMN IF NOT EXISTS `registry_completed` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `registry_completed_at` DATETIME DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_registry_completed` (`registry_completed`);

-- 2. Create guest registries audit log table if missing
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
```

---

## 4. Environment Variables & Credential Configuration

Access credentials and integration secrets are managed in `public/api/config.php` and can be overridden via server environment variables (`.env`, Apache `SetEnv`, Nginx `fastcgi_param`, or systemd environment):

### 4.1 Property Access Credentials
| Variable | Description | Default Fallback |
| :--- | :--- | :--- |
| `PROPERTY_1606_DOOR_CODE` | Keypad PIN for Apartment 1606 | `1606#` |
| `PROPERTY_1606_WIFI_SSID` | Wi-Fi Network Name for 1606 | `APTO1606` |
| `PROPERTY_1606_WIFI_PASSWORD` | Wi-Fi Password for 1606 | `Invitado@1606@HN` |
| `PROPERTY_1707_DOOR_CODE` | Keypad PIN for Apartment 1707 | `1707#` |
| `PROPERTY_1707_WIFI_SSID` | Wi-Fi Network Name for 1707 | `APTO1707` |
| `PROPERTY_1707_WIFI_PASSWORD` | Wi-Fi Password for 1707 | `Invitado@1707@HN` |

> [!TIP]
> **Zero-Downtime Door Code Rotation**: To rotate the smart door lock code after a guest checkout or security audit, simply update `PROPERTY_1606_DOOR_CODE` in your environment (or `config.php`). Do **not** rebuild the frontend (`npm run build`), as the client queries `/api/guide-access.php` at runtime.

### 4.2 Integration & Service Keys
| Variable | Description |
| :--- | :--- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL database connection credentials. |
| `GOOGLE_SHEET_WEBAPP_URL` | Target endpoint for Google Apps Script Web App logging. |
| `RECIPIENT_EMAIL` | Destination mailbox receiving guest registry reports and booking alerts. |
| `SEND_CUSTOMER_EMAILS` | Set to `'1'` to enable transactional email dispatch via PHP `mail()`. |
| `MERCADOPAGO_ACCESS_TOKEN` | Mercado Pago private access token for payment settlement. |

---

## 5. System Architecture & Component Interaction

```
[Guest Browser]
       │
       ▼
1. Requests /guide/?code=ovf_abc123&property=1606&lang=es
       │
       ├─► Static HTML loads with masked placeholders ("••••••")
       │
       ▼
2. Client JS fetches: /api/guide-access.php?code=ovf_abc123&lang=es
       │
       ▼
[GuideAccessService]
       │
       ├─► Look up reservation in MySQL (PdoReservationRepository)
       │     │
       │     ├─► Status != 'confirmed' ──► Returns 403 / "unauthorized" (locked)
       │     │
       │     ├─► registry_completed == 0 ──► Returns 200 / "registry_required"
       │     │                               (Locked + pre-filled registry link)
       │     │
       │     └─► registry_completed == 1 ──► Returns 200 / "verified"
       │                                     (Releases door PIN & Wi-Fi)
       ▼
3. Client unmasks credentials OR displays banner directing guest to /registry/
```

---

## 6. Rate Limiting & Storage Considerations

The API enforces file-based rate limiting via `enforce_rate_limit()` in `public/api/utils.php`:

| Endpoint | Storage File in `sys_get_temp_dir()` | Window | Max Requests |
| :--- | :--- | :--- | :--- |
| `/api/guide-access.php` | `ovf_guide_access_rate_limits.json` | 10 minutes | 60 requests per IP hash |
| `/api/registry-processor.php` | `ovf_registry_rate_limits.json` | 10 minutes | 10 requests per IP hash |
| `/api/book-request.php` | `ovf_booking_rate_limits.json` | 10 minutes | 10 requests per IP hash |

### Operational Caveats:
1. **File Permissions**: The system temporary folder (`/tmp` or `sys_get_temp_dir()`) must be writable by the web server process user (`www-data`, `nginx`, or `apache`).
2. **Shared IP / Corporate VPNs**: If an entire travel group or condominium lobby network accesses the guide repeatedly on the same public Wi-Fi, they might exceed the 60 requests / 10 min threshold.
3. **Emergency Reset**: If an IP is accidentally locked out during testing or legitimate guest usage, an administrator can safely flush rate limits without restarting services:
   ```bash
   rm -f /tmp/ovf_*_rate_limits.json
   ```

---

## 7. Administrator Troubleshooting & Support Playbook

### Scenario A: Guest states "The door code shows dots (••••••) and the Copy button does nothing."
* **Root Cause 1: Guest has not submitted the guest registry.**
  * *Diagnostic Query*:
    ```sql
    SELECT reservation_uid, guest_name, status, registry_completed 
    FROM reservations WHERE reservation_uid = 'ovf_...';
    ```
  * *Resolution*: If `registry_completed = 0`, remind the guest to click the blue banner button: *"Complete Registration to Unlock"*.
  * *Emergency Override*: If the guest has already submitted their ID details in person, via WhatsApp, or on paper, manually unlock their reservation:
    ```sql
    UPDATE reservations 
    SET registry_completed = 1, registry_completed_at = NOW() 
    WHERE reservation_uid = 'ovf_...';
    ```
* **Root Cause 2: Booking is not in `confirmed` status.**
  * *Diagnostic Query*: Check `status` in `reservations`. If it is `pending_payment`, Mercado Pago has not accredited the transaction or cash hold has not been verified.
* **Root Cause 3: The URL does not have the reservation code parameter.**
  * *Diagnostic*: Check if the guest opened `/guide/` instead of `/guide/?code=ovf_...`.
  * *Resolution*: Provide the complete link: `https://oceanviewflats.com/guide/?code=ovf_...&property=1606&lang=es`.

### Scenario B: Guest submitted the registry, but the guide is still locked.
* **Root Cause: The registry was submitted without the reservation identifier.**
  * If a guest navigated directly to `/registry/` rather than following the personalized link from their email, the submission did not include `reservation_code`.
  * *Resolution*:
    1. Inspect the `guest_registries` table to find the submission by guest name or dates:
       ```sql
       SELECT * FROM guest_registries ORDER BY created_at DESC LIMIT 5;
       ```
    2. Match the reservation and update it:
       ```sql
       UPDATE reservations 
       SET registry_completed = 1, registry_completed_at = NOW() 
       WHERE property_id = '1606' AND check_in = 'YYYY-MM-DD';
       ```

### Scenario C: Error 503 "Database service is temporarily unavailable".
* **Root Cause: MySQL connection failure.**
  * Verify MySQL service status: `systemctl status mysql` (or `mariadb`).
  * Verify database credentials in `public/api/config.php` or environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
  * Inspect PHP error log: `tail -n 50 /var/log/php-fpm/www-error.log` (or `/var/log/apache2/error.log`).

---

## 8. Post-Deployment Verification Checklist

Upon deploying to staging or production, the administrator should execute this verification checklist:

- [ ] **Run Migration**: Execute `php scripts/migrate.php` and confirm `=== Database Migrations Completed Successfully! ===`.
- [ ] **Verify Schema**: Execute `DESCRIBE reservations;` in MySQL to confirm `registry_completed` is present.
- [ ] **Verify Rate Limit Directory**: Ensure `/tmp/` is writable by PHP-FPM.
- [ ] **Test Gated Access Endpoint (Locked State)**:
  ```bash
  curl -i "https://oceanviewflats.com/api/guide-access.php?code=nonexistent"
  # Expected HTTP 404 with {"success":false,"status":"not_found"}
  ```
- [ ] **Test Registry Gating with Confirmed Booking**:
  1. Locate a test confirmed booking with `registry_completed = 0`.
  2. Curl endpoint:
     ```bash
     curl -i "https://oceanviewflats.com/api/guide-access.php?code=ovf_test_code"
     ```
  3. Verify HTTP 200 with `"status":"registry_required"`, `"verified":false`, and credentials **not present**.
- [ ] **Test Unlocked State**:
  1. Mark test booking completed: `UPDATE reservations SET registry_completed = 1 WHERE reservation_uid = 'ovf_test_code';`.
  2. Curl endpoint again.
  3. Verify HTTP 200 with `"status":"verified"`, `"verified":true`, and `"credentials":{"door_code":"...","wifi_ssid":"...","wifi_password":"..."}`.
- [ ] **Run Automated Test Suite (CI/CD)**:
  ```bash
  ./vendor/bin/phpunit
  npm run build
  ```
