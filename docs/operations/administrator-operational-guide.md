# OceanViewFlats - Administrator Operational & Breaking Changes Guide
**Target Audience**: Application Administrators, Systems Engineers, Operations & Support Staff  
**Architecture Version**: Clean Architecture, ADR 0001/0004 Gating, & ADR 0005 Admin Subdomain Isolation (Issues #9, #10, #11, #74, #79)  
**Date**: September 2026  

---

## 1. Executive Summary

Recent architectural refactorings have transitioned the OceanViewFlats platform from loosely coupled, client-authoritative scripts into a domain-driven, server-authoritative architecture with isolated administrative tooling:
1. **Statutory Hospitality & Security Compliance ([ADR 0001](../adr/0001-mandatory-registry-before-access.md))**: Complete withholding of door keypad PINs and Wi-Fi credentials until the primary guest submits the official Colombian Guest Registry.
2. **Elimination of Plaintext Credentials**: Complete eradication of sensitive access credentials from URL GET query parameters, browser histories, and pre-rendered static artifacts.
3. **Server-Authoritative Pricing ([ADR 0004](../adr/0004-authoritative-quote-engine-and-itemized-pricing.md))**: Centralizing night-by-night seasonal pricing, cleaning fees, and lobby fees within an authoritative backend quote engine to eliminate client-side pricing drift.
4. **Decoupled Fulfillment Infrastructure**: Isolating email rendering, mail delivery, and Google Spreadsheet webhooks behind domain interfaces (Ports & Adapters) to ensure atomic database state and zero transactional crashes.
5. **Subdomain-Isolated Administration ([ADR 0005](../adr/0005-admin-interface-architecture-and-subdomain-isolation.md))**: Centralizing property management system (PMS) workflows on an isolated subdomain (`admin.oceanviewflats.com`) using cPanel Pattern A topology, keeping application controllers, shared domain models, Composer dependencies, and CLI migration tools strictly outside the public web root.

---

## 2. Summary of Breaking Changes & Architectural Enhancements

| Area | Prior Behavior (Legacy) | New Behavior (Current) | Operational Impact |
| :--- | :--- | :--- | :--- |
| **Admin Interface & Subdomain** | No dedicated admin portal; direct database manipulation required. | Standalone administrative portal hosted at `admin.oceanviewflats.com` (DocumentRoot: `/home/<user>/admin/public`). | Operations staff manage bookings, calendar blocks, and rate overrides via a hardened, server-rendered HTMX interface. |
| **Guest Guide URLs** | `/guide/?doorCode=1606#&wifi=...` displayed credentials directly from query string. | Query parameters `doorCode`, `door_code`, and `wifi` are **completely ignored**. The guide only accepts `?code={reservation_uid}` and queries `/api/guide-access.php`. | **CRITICAL**: Any manual message or CRM template sending `doorCode` in links will leave the guest with locked credentials (`••••••`). Templates must be updated. |
| **Confirmation Emails** | Direct booking receipts included door PINs and Wi-Fi passwords immediately upon payment. | Confirmation emails **strictly omit** door codes, Wi-Fi passwords, and direct guide URLs. They contain an invitation button to `/registry/?code=...`. | Guests must complete the registration form before they receive access credentials. Support staff must not manually hand out PINs without registry submission. |
| **Database Schema** | `reservations` table lacked registration status and door code tracking; no admin tables. | `reservations` tracks `registry_completed` and dynamic `door_code`; `guest_registries` audits guest IDs; `admin_users` and `admin_audit_logs` manage authenticated staff. | **MANDATORY**: Administrator must run `php scripts/migrate.php` on production. Omission causes SQL fatal errors on registry, access, and admin endpoints. |
| **Credential Storage & Dynamic PINs** | Door codes were static and identical across all guests. | Lock requires a **7-digit PIN followed by '#'** (`0XXXXXX#`), dynamically generated per guest from the primary guest's Government ID/Passport (with phone fallback) upon Guest Registry completion. The code is saved to `reservations.door_code` and emailed to `RECIPIENT_EMAIL` so staff can program the physical lock in its companion app. | Staff must program the generated 7-digit PIN into the external smart-lock platform upon receiving the guest registry report. If needed, admins can manually override `door_code` via the admin portal or directly in MySQL. |
| **Direct Quote Calculation** | Client JavaScript calculated subtotals from static JSON; backend verified superficial totals. | Backend `SeasonalPricer` authoritatively computes night-by-night rates, minimum stays, and fees from `public/data/prices.csv`. Client totals are ignored. | Any change in property nightly pricing or minimum-stay tiers must be made in `public/data/prices.csv`. |

---

## 3. cPanel Subdomain Configuration (Pattern A Topology)

### 3.1 Webroot Isolation & Pattern A Overview

The administrative subsystem is deployed using **cPanel Pattern A**, which decouples the admin front-controller entry point from sensitive source code, vendor libraries, and database tools:

```text
/home/<cpanel_user>/
├── public_html/                 <-- DocumentRoot for oceanviewflats.com (Public SSG)
│   ├── index.html
│   ├── js/
│   ├── css/
│   └── api/                     <-- Public REST APIs (calls /home/<user>/vendor/autoload.php)
│       ├── config.php
│       └── ...
├── admin/
│   ├── public/                  <-- DocumentRoot for admin.oceanviewflats.com (Pattern A)
│   │   ├── index.php            <-- Administrative Front-Controller
│   │   └── .htaccess            <-- URL Rewriting & HTTP Security Headers
│   ├── src/                     <-- Admin Application Controllers, Auth, & Http Handlers
│   └── templates/               <-- Server-rendered PHP Templates (HTMX Components)
├── src/
│   └── Domain/                  <-- Shared Domain Models (QuoteEngine, Ledger, DoorCode)
├── vendor/                      <-- Production Composer Autoloader & Dependencies
└── scripts/                     <-- CLI Utilities (migrate.php, create-admin-user.php)
```

> [!IMPORTANT]
> **Why Pattern A?**
> In standard cPanel setups (Pattern B), subdomains are often created inside `public_html/admin`. If an Apache `.htaccess` rule fails or mod_rewrite is misconfigured, raw PHP files in nested folders can become readable over HTTP.
> **Pattern A** places the subdomain DocumentRoot at `/home/<user>/admin/public`. The application logic (`admin/src/`), templates (`admin/templates/`), domain core (`src/Domain/`), Composer dependencies (`vendor/`), and migration scripts (`scripts/`) reside entirely **outside any web-accessible root**. They are physically impossible to access or execute via HTTP requests.

### 3.2 Step-by-Step Subdomain Creation in cPanel

Follow these steps to establish the isolated subdomain in cPanel:

1. **Log in to cPanel** using your administrative credentials.
2. Navigate to the **Domains** section and click **Domains** (or **Subdomains**, depending on your cPanel theme).
3. Click the **Create A New Domain** button.
4. In the **Domain** input field, enter:
   ```text
   admin.oceanviewflats.com
   ```
5. **Deselect / Uncheck** the checkbox labeled:
   > *"Share document root (/home/username/public_html) with 'domain.com'"*
6. In the **Document Root (file system location)** input field, specify:
   ```text
   admin/public
   ```
   *(cPanel automatically prefixes this with your home directory, resolving to `/home/<username>/admin/public`)*.
7. Click **Submit**.

### 3.3 AutoSSL / Let's Encrypt Activation

All administrative traffic requires end-to-end TLS encryption (HTTPS) with HTTP-only, secure session cookies:

1. In cPanel, navigate to the **Security** section and click **SSL/TLS Status**.
2. Locate `admin.oceanviewflats.com` in the domain certificate list.
3. If the certificate is not yet issued or shows an expired badge, check the checkbox next to `admin.oceanviewflats.com`.
4. Click the **Run AutoSSL** button at the top of the interface.
5. cPanel will trigger an automated HTTP-01 challenge via Let's Encrypt or Sectigo cPanel AutoSSL.
6. Refresh the page after 1–2 minutes. Verify that a **green lock icon** appears next to `admin.oceanviewflats.com`.
7. Return to **Domains** -> **Domains**, locate `admin.oceanviewflats.com`, and ensure the **Force HTTPS Redirect** toggle switch is enabled (**ON**).

---

## 4. CI/CD Deployment & Secrets Configuration

### 4.1 Dual-Pipeline Deployment Architecture

The repository utilizes two decoupled GitHub Actions deployment workflows to maintain separation of concerns:

1. **Public Static Site Pipeline (`.github/workflows/deploy.yml`)**:
   - **Trigger**: Pushes to `main`.
   - **Build**: Compiles the React/TypeScript Static Site Generator into static HTML (`dist/`).
   - **Target**: Deploys `./dist/.` to `FTP_REMOTE_PATH` (`/home/<user>/public_html/`) via SFTP.
   - **Purpose**: Fast marketing site updates without backend overhead.
2. **Admin & Domain/Vendor Pipeline (`.github/workflows/admin-deploy.yml`)**:
   - **Trigger**: Pushes to `main` modifying `admin/**`, `src/Domain/**`, `scripts/**`, `composer.json`, or `composer.lock`.
   - **Build**: Sets up PHP 8.3, installs production Composer dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`, and injects environment variables into `admin/public/.htaccess`.
   - **Payload**: Packages `admin/`, `src/Domain/`, `vendor/`, and `scripts/` (excluding tests, PHPUnit configs, and PHPStan metadata).
   - **Target**: Deploys the staged payload to `REMOTE_DESTINATION` (`/home/<user>/`) via SFTP.
   - **Purpose**: Independent, zero-downtime releases for backend logic and administrative tools.

### 4.2 GitHub Actions Secrets Catalog

Configure the following secrets in GitHub under **Repository Settings** -> **Secrets and variables** -> **Actions**:

| Secret Name | Required In | Description | Example / Target Value |
| :--- | :--- | :--- | :--- |
| `FTP_SERVER` | Both | cPanel server hostname or IP address. | `cpanel.oceanviewflats.com` or `198.51.100.25` |
| `FTP_USERNAME` | Both | SFTP / SSH deployment username. | `cpaneluser` |
| `FTP_PASSWORD` | Both | SFTP / SSH deployment password or key. | `StrongDeploymentPassword#123` |
| `FTP_REMOTE_PATH` | Both | Public static document root remote path. | `/home/cpaneluser/public_html` |
| `FTP_ADMIN_REMOTE_PATH` | `admin-deploy.yml` | Base home directory for admin/vendor payload. | `/home/cpaneluser` *(Defaults to `dirname(FTP_REMOTE_PATH)` if unset)* |
| `DB_HOST` | Both | MySQL database host on cPanel server. | `127.0.0.1` or `localhost` |
| `DB_NAME` | Both | Dedicated MySQL database name. | `cpaneluser_oceanviewflats_db` |
| `DB_USER` | Both | MySQL database user with full DDL/DML rights. | `cpaneluser_dbuser` |
| `DB_PASS` | Both | MySQL database user password. | `DBSecretPass#2026` |
| `MERCADOPAGO_ACCESS_TOKEN` | Both | Mercado Pago private production access token. | `APP_USR-654321-...` |
| `MERCADOPAGO_PUBLIC_KEY` | Both | Mercado Pago public key for frontend Brick rendering. | `APP_USR-123456-...` |
| `MERCADOPAGO_SANDBOX` | Both | Flag controlling payment sandbox mode. | `false` |
| `PROPERTY_1606_DOOR_CODE` | Both | Emergency fallback keypad PIN for Apartment 1606. | `0160600#` |
| `PROPERTY_1606_WIFI_SSID` | Both | Wi-Fi network SSID for Apartment 1606. | `APTO1606` |
| `PROPERTY_1606_WIFI_PASSWORD` | Both | Wi-Fi network password for Apartment 1606. | `Invitado@1606@HN` |
| `PROPERTY_1707_DOOR_CODE` | Both | Emergency fallback keypad PIN for Apartment 1707. | `0170700#` |
| `PROPERTY_1707_WIFI_SSID` | Both | Wi-Fi network SSID for Apartment 1707. | `APTO1707` |
| `PROPERTY_1707_WIFI_PASSWORD` | Both | Wi-Fi network password for Apartment 1707. | `Invitado@1707@HN` |
| `OVF_ADMIN_SESSION_SECRET` | `admin-deploy.yml` | Cryptographic secret for signing session tokens & CSRF cookies. | 64-char random hex string (`openssl rand -hex 32`) |
| `RECIPIENT_EMAIL` | `deploy.yml` | Mailbox receiving booking notifications and guest registry alerts. | `reservas@oceanviewflats.com` |
| `CAPTCHA_SECRET` | `deploy.yml` | HMAC key for signing mathematical captcha tokens. | 32-char random alphanumeric string |
| `GOOGLE_SHEET_WEBAPP_URL` | `deploy.yml` | Google Apps Script endpoint URL for booking sync. | `https://script.google.com/macros/s/.../exec` |

> [!NOTE]
> Both deployment workflows connect via **SFTP Port 2223**, which is cPanel's custom SSH/SFTP port configured in the GitHub Actions runner.

---

## 5. Database Migration & Schema Management

### 5.1 Remote Migration Execution via cPanel Terminal or SSH

Database structural changes must **never** occur inline within HTTP request lifecycles. All schema migrations are centralized in `scripts/migrate.php` and executed via CLI:

#### Method A: Via SSH
Connect to your cPanel host using the dedicated SFTP/SSH port (`2223`):
```bash
ssh -p 2223 cpaneluser@cpanel.oceanviewflats.com
```

#### Method B: Via cPanel Terminal
1. Log in to cPanel.
2. Under the **Advanced** section, click **Terminal**.
3. Accept the terminal warning if opening for the first time.

#### Executing the Migrator:
Once inside the terminal shell at your home directory (`/home/<user>/`):
```bash
pwd
# Expected output: /home/<user>

php scripts/migrate.php
```

#### Expected Migration Output:
```text
=== OceanViewFlats Database Migration Running ===
Connected to MySQL server on 127.0.0.1
Database `cpaneluser_oceanviewflats_db` selected/created.
Table `reservations` verified.
Table `payment_idempotency` verified.
Table `guest_registries` verified.
Table `admin_users` verified.
Table `admin_audit_logs` verified.
Columns and indexes verified:
 - reservations.registry_completed
 - reservations.registry_completed_at
 - reservations.door_code
 - reservations.refunded_amount
 - reservations.source
 - admin_users.failed_login_attempts
 - admin_users.locked_until
=== Database Migrations Completed Successfully! ===
```

### 5.2 Schema Elements Managed by `scripts/migrate.php`

1. **`reservations` Table**:
   - `registry_completed`: `TINYINT(1) NOT NULL DEFAULT 0` (Blocks guide access until Colombian registry submission).
   - `registry_completed_at`: `DATETIME DEFAULT NULL` (Timestamp of legal registry receipt).
   - `door_code`: `VARCHAR(20) DEFAULT NULL` (Dynamically generated 7-digit PIN + '#').
   - `refunded_amount`: `DECIMAL(10, 2) NOT NULL DEFAULT 0.00` (Refund tracking).
   - `source`: `VARCHAR(30) NOT NULL DEFAULT 'web'` (Tracks origin: direct, airbnb, manual).
2. **`guest_registries` Table**:
   - Audits complete legal guest submissions (names, ID types, document numbers, ages, vehicle plates, vehicle model, IP address, timestamp).
   - Indexed on `reservation_uid` and `(property_id, check_in, check_out)`.
3. **`payment_idempotency` Table**:
   - Prevents double-charging on checkout retries or duplicate webhook delivery.
4. **`admin_users` Table**:
   - Holds administrative accounts with Argon2id password hashes, role authorizations (`admin`, `superadmin`, `manager`, `viewer`), active status flags, failed login attempt counters, and lockout expiration timestamps.
5. **`admin_audit_logs` Table**:
   - Immutable audit trail recording user ID, action, entity type, entity ID, metadata JSON payload, IP address, user agent, and timestamp.

### 5.3 Emergency SQL (Manual Execution Fallback)

If CLI terminal access is temporarily restricted, run the complete schema script [`scripts/schema.sql`](../../scripts/schema.sql) directly inside cPanel **phpMyAdmin** or MySQL command line.

---

## 6. Administrator User Provisioning & Credential Runbook

### 6.1 CLI Provisioning Utility Overview

Administrator accounts are bootstrapped and managed exclusively through the CLI utility `scripts/create-admin-user.php`.

**Security Features**:
- **CLI-Only Enforcement**: Rejects non-CLI invocations with HTTP 403 / exit code 1 to prevent web execution.
- **Argon2id Hashing**: Uses `PASSWORD_ARGON2ID` with high-security memory cost (65,536 KiB), time cost (4 iterations), and thread cost (1 thread).
- **Redaction**: Passwords are never echoed or written to terminal history, stdout, stderr, or system error logs.
- **Idempotent Upsert**: Safely updates existing records without duplicate key errors, resetting failed login locks and updating roles seamlessly.

### 6.2 Initial Super-Admin Provisioning

To provision the initial administrator account after deployment, connect via cPanel Terminal or SSH and execute:

```bash
php scripts/create-admin-user.php \
  --email="admin@oceanviewflats.com" \
  --name="Property Administrator" \
  --password="YourSecureMasterPassword123!" \
  --role="admin"
```

**Supported Arguments**:
- `--email` (or `-e`): Valid email address used for administrative login.
- `--name` (or `-n`): Full name of the administrator.
- `--password` (or `-p`): Plaintext password (must be at least 8 characters long).
- `--role` (or `-r`): Access role (`admin`, `superadmin`, `manager`, `viewer`; defaults to `admin`).

**Expected Success Output**:
```text
Admin user successfully created: admin@oceanviewflats.com (Role: admin)
```

### 6.3 Password Rotation & Account Reactivation

If an administrator forgets their password, or if an account is locked due to 5 consecutive failed login attempts:

1. Run `scripts/create-admin-user.php` with the administrator's email and new password:
   ```bash
   php scripts/create-admin-user.php \
     --email="admin@oceanviewflats.com" \
     --name="Property Administrator" \
     --password="NewRotatedPassword456!" \
     --role="admin"
   ```
2. **Automated Account Reset**:
   - Re-hashes the new password with Argon2id.
   - Clears `locked_until` (unlocks account immediately).
   - Resets `failed_login_attempts` to `0`.
   - Ensures `is_active = 1`.
3. **Expected Output**:
   ```text
   Admin user successfully updated: admin@oceanviewflats.com (Role: admin)
   ```

---

## 7. Smart Lock PIN Generation & Configuration

### 7.1 Lock Hardware Requirements & Generation Algorithm
OceanViewFlats physical keypad locks require a **7-digit code followed by the '#' key**. Because smart lock credentials change from guest to guest and are programmed into an external companion app (Tuya / TTLock / Yale):
1. **Source**: The Primary Guest's Government ID / Passport number (`doc_num`) entered in the Guest Registry.
2. **Extraction**:
   - All non-digits are stripped (`preg_replace('/\D/', '', $docNum)`).
   - If the ID has no digits (e.g. rare alphabetic document), digits are extracted from the primary guest's phone number.
   - The last 6 digits are extracted and padded with leading `'0'` if fewer than 6 digits are present.
   - The final PIN is prefixed with `'0'` and followed by `'#'` (Format: `0XXXXXX#`).
3. **Delivery to Operations Staff**:
   - The generated PIN is sent immediately in the registry notification email to `RECIPIENT_EMAIL` with a prominent `SMART LOCK ACCESS PIN (ACTION REQUIRED)` banner.
   - The PIN is recorded in `reservations.door_code` and transmitted in the Google Sheets webhook payload.
4. **Manual Admin Override**:
   - If the lock is manually programmed with a custom PIN, the administrator can override it in the admin interface (`admin.oceanviewflats.com`) or directly in the database:
     ```sql
     UPDATE reservations SET door_code = '0987654#' WHERE reservation_uid = 'ovf_...';
     ```

### 7.2 Property Access Credentials & Fallback Environment Variables
| Variable | Description | Default Fallback |
| :--- | :--- | :--- |
| `PROPERTY_1606_DOOR_CODE` | Keypad PIN fallback for Apartment 1606 (7 digits + #) | `0160600#` |
| `PROPERTY_1606_WIFI_SSID` | Wi-Fi Network Name for 1606 | `APTO1606` |
| `PROPERTY_1606_WIFI_PASSWORD` | Wi-Fi Password for 1606 | `Invitado@1606@HN` |
| `PROPERTY_1707_DOOR_CODE` | Keypad PIN fallback for Apartment 1707 (7 digits + #) | `0170700#` |
| `PROPERTY_1707_WIFI_SSID` | Wi-Fi Network Name for 1707 | `APTO1707` |
| `PROPERTY_1707_WIFI_PASSWORD` | Wi-Fi Password for 1707 | `Invitado@1707@HN` |

> [!TIP]
> **Zero-Downtime Door Code Rotation**: To rotate the fallback door lock code after a security audit, update `PROPERTY_1606_DOOR_CODE` in your GitHub Actions secrets and trigger a redeployment. Do **not** rebuild the frontend (`npm run build`), as the client queries `/api/guide-access.php` at runtime.

---

## 8. System Architecture & Component Interaction

```text
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

```text
[Staff / Administrator Browser]
       │
       ▼
1. Requests https://admin.oceanviewflats.com/login
       │
       ▼
[admin/public/index.php Front-Controller]
       │
       ├─► SessionMiddleware: Starts cookie session (Secure, HttpOnly, SameSite=Lax)
       ├─► ConfigPathResolver: Resolves DB config from /home/<user>/public_html/api/config.php
       ├─► AuthService: Authenticates credentials against admin_users (Argon2id)
       │
       ▼
2. Admin Dashboard & PMS Interface
       │
       ├─► Reservations Ledger (View, Filter, Edit, Cancel, Override Door Codes)
       ├─► Calendar Sync & Property Blocks (Airbnb iCal import/export)
       ├─► Rate Engine & Seasonal Overrides
       └─► Audit Trail Viewer (admin_audit_logs)
```

---

## 9. Rate Limiting & Storage Considerations

The API enforces file-based rate limiting via `enforce_rate_limit()` in `public/api/utils.php`:

| Endpoint | Storage File in `sys_get_temp_dir()` | Window | Max Requests |
| :--- | :--- | :--- | :--- |
| `/api/guide-access.php` | `ovf_guide_access_rate_limits.json` | 10 minutes | 60 requests per IP hash |
| `/api/registry-processor.php` | `ovf_registry_rate_limits.json` | 10 minutes | 10 requests per IP hash |
| `/api/book-request.php` | `ovf_booking_rate_limits.json` | 10 minutes | 10 requests per IP hash |
| `admin.oceanviewflats.com/login` | Database-backed (`admin_users.failed_login_attempts`) | 15 minutes lockout | 5 failed attempts per account |

### Operational Caveats:
1. **File Permissions**: The system temporary folder (`/tmp` or `sys_get_temp_dir()`) must be writable by the web server process user (`www-data`, `nobody`, or `cpaneluser`).
2. **Shared IP / Corporate VPNs**: If an entire travel group or condominium lobby network accesses the guide repeatedly on the same public Wi-Fi, they might exceed the 60 requests / 10 min threshold.
3. **Emergency Reset**: If an IP is accidentally locked out during testing or legitimate guest usage, an administrator can safely flush rate limits without restarting services:
   ```bash
   rm -f /tmp/ovf_*_rate_limits.json
   ```

---

## 10. Administrator Troubleshooting & Support Playbook

### Scenario A: Guest states "The door code shows dots (••••••) and the Copy button does nothing."
* **Root Cause 1: Guest has not submitted the guest registry.**
  * *Diagnostic Query*:
    ```sql
    SELECT reservation_uid, guest_name, status, registry_completed 
    FROM reservations WHERE reservation_uid = 'ovf_...';
    ```
  * *Resolution*: If `registry_completed = 0`, remind the guest to click the blue banner button: *"Complete Registration to Unlock"*.
  * *Emergency Override*: If the guest has already submitted their ID details in person, via WhatsApp, or on paper, manually unlock their reservation via the admin portal (`admin.oceanviewflats.com`) or SQL:
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
  * Inspect PHP error log: `tail -n 50 /var/log/php-fpm/www-error.log` (or `/usr/local/apache/logs/error_log` on cPanel).

### Scenario D: `admin.oceanviewflats.com` returns 404 on clean URLs (`/reservations`, `/rates`, `/login`).
* **Root Cause: Apache mod_rewrite is missing or `.htaccess` in `admin/public` is missing.**
  * Ensure `admin/public/.htaccess` exists with `RewriteEngine On` and `RewriteRule ^(.*)$ index.php [QSA,L]`.
  * Verify cPanel DocumentRoot for `admin.oceanviewflats.com` is set to `admin/public` (not `admin` or `public_html/admin`).

### Scenario E: Admin login displays "Account temporarily locked out".
* **Root Cause: 5 consecutive failed login attempts triggered automated brute-force protection.**
  * Reset and unlock account immediately via CLI:
    ```bash
    php scripts/create-admin-user.php --email="admin@oceanviewflats.com" --name="Admin" --password="NewPassword123!"
    ```

---

## 11. Post-Deployment Verification Checklist

Upon deploying to staging or production, execute this complete verification checklist:

- [ ] **Run Database Migrations**:
  ```bash
  php scripts/migrate.php
  ```
  Confirm output terminates with `=== Database Migrations Completed Successfully! ===`.
- [ ] **Verify Schema & Tables**:
  Execute `SHOW TABLES;` in MySQL to verify `reservations`, `guest_registries`, `payment_idempotency`, `admin_users`, and `admin_audit_logs`.
- [ ] **Provision Initial Super-Admin Account**:
  ```bash
  php scripts/create-admin-user.php --email="admin@oceanviewflats.com" --name="Super Admin" --password="<secure-password>" --role="superadmin"
  ```
- [ ] **Verify AutoSSL Status**:
  Visit `https://admin.oceanviewflats.com/login` and verify that the browser presents a valid SSL/TLS certificate without warnings.
- [ ] **Test Admin Front-Controller Clean URLs**:
  Verify navigating to `/login`, `/reservations`, and `/calendar-blocks` resolves through `index.php` without Apache 404 errors.
- [ ] **Test Admin Authentication & Lockout**:
  1. Attempt login with invalid credentials; verify error message.
  2. Log in with valid credentials; verify session cookie `ovf_admin_session` is marked `Secure`, `HttpOnly`, and `SameSite=Lax`.
- [ ] **Verify Public Gated Access Endpoint (Locked State)**:
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
  1. Mark test booking completed:
     ```sql
     UPDATE reservations SET registry_completed = 1 WHERE reservation_uid = 'ovf_test_code';
     ```
  2. Curl endpoint again.
  3. Verify HTTP 200 with `"status":"verified"`, `"verified":true`, and `"credentials":{"door_code":"...","wifi_ssid":"...","wifi_password":"..."}`.
- [ ] **Run Automated Test Suites**:
  ```bash
  composer test
  composer admin:test
  composer admin:phpstan
  ```
