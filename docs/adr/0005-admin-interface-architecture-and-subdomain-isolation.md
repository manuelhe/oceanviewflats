# Admin Interface Architecture, Subdomain Isolation, and Dual-Pipeline Deployment (Pattern A)

## Context

The OceanViewFlats platform combines a high-performance, guest-facing direct booking engine with comprehensive property management system (PMS) operations, including reservation ledger management, rate overrides, calendar blocks, and legal guest registry audits. 

Historically, public marketing views have been generated via a static site generator (SSG) compiled to `dist/` and deployed directly to cPanel's public web root (`public_html/`). However, introducing administrative capabilities demanded an architectural design that satisfies four strict criteria:
1. **Security Isolation**: Administrative tools, authenticated session perimeters, and internal business logic must be physically and logically separated from public guest traffic.
2. **Zero Code Duplication**: Core business logic (nightly seasonal quote calculation, calendar availability, reservation ledgers, and dynamic door PIN generation) must remain canonical and shared between guest-facing endpoints and admin views.
3. **Protected Source Topology**: Non-public PHP source code (`admin/src/`, `src/Domain/`), third-party packages (`vendor/`), and infrastructure CLI scripts (`scripts/`) must never be accessible over HTTP.
4. **Decoupled Deployment Lifecycles**: Backend administrative updates or domain model fixes must deploy independently without triggering slow compilation of public marketing pages, and vice versa.

## Decision

We establish a dedicated administrative application served from an isolated subdomain (`admin.oceanviewflats.com`) utilizing **cPanel Pattern A** topology and a **Dual-Pipeline CI/CD architecture**.

### 1. Webroot Topology: Pattern A vs. Pattern B
Rather than nesting the administrative application inside `public_html/admin` (Pattern B), we configure cPanel with Pattern A:
* **Subdomain DocumentRoot**: Explicitly mapped to `/home/<user>/admin/public`.
* **Out-of-Webroot Isolation**: All sensitive application code (`admin/src/`), templates (`admin/templates/`), domain core (`src/Domain/`), Composer dependencies (`vendor/`), and CLI management utilities (`scripts/`) reside directly under `/home/<user>/`, outside all DocumentRoots.
* **Apache Front-Controller**: An `.htaccess` file inside `admin/public/` enforces `DirectoryIndex index.php`, disables directory indexing (`Options -Indexes`), applies strict security headers (`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`), and rewrites all non-file/non-directory HTTP requests through `index.php`.

```text
/home/<user>/
├── public_html/                 <-- DocumentRoot for oceanviewflats.com (Public SSG)
│   ├── index.html
│   ├── css/ & js/
│   └── api/                     <-- Public REST APIs (calls ../vendor/autoload.php)
├── admin/
│   ├── public/                  <-- DocumentRoot for admin.oceanviewflats.com (Pattern A)
│   │   ├── index.php            <-- Administrative Front-Controller
│   │   └── .htaccess            <-- Rewrite & Security Headers
│   ├── src/                     <-- Protected Admin Application Logic
│   └── templates/               <-- Server-rendered PHP Templates (HTMX Components)
├── src/
│   └── Domain/                  <-- Shared Domain Models (QuoteEngine, Ledger, DoorCode)
├── vendor/                      <-- Production Composer Autoloader & Dependencies
└── scripts/                     <-- CLI Utilities (migrate.php, create-admin-user.php)
```

### 2. Dual-Pipeline CI/CD Deployment Architecture
Deployments are strictly segregated across two independent GitHub Actions workflows:

1. **Public Static Site Pipeline (`.github/workflows/deploy.yml`)**:
   - Compiles React components and Tailwind CSS into static assets (`dist/`).
   - Injects public environment secrets (`RECIPIENT_EMAIL`, `CAPTCHA_SECRET`, database and payment keys) into `public/.htaccess`.
   - Transfers `./dist/.` to `/home/<user>/public_html/` via SFTP on port 2223.
2. **Admin & Backend Pipeline (`.github/workflows/admin-deploy.yml`)**:
   - Triggers on changes to `admin/**`, `src/Domain/**`, `scripts/**`, `composer.json`, or `composer.lock`.
   - Installs production Composer dependencies (`composer install --no-dev --prefer-dist --optimize-autoloader`).
   - Injects production secrets (`DB_*`, `MERCADOPAGO_*`, `PROPERTY_*`, `OVF_ADMIN_SESSION_SECRET`) into `admin/public/.htaccess`.
   - Stages a clean payload containing `admin/`, `src/Domain/`, `vendor/`, and `scripts/` (omitting test files and dev configurations).
   - Transfers the payload to `/home/<user>/` via SFTP on port 2223.

### 3. Shared Runtime Autoloading & Path Resilience
* **Single Source of Truth**: The production Composer autoloader at `/home/<user>/vendor/autoload.php` serves both the admin application (`admin/public/index.php`) and public APIs (`public_html/api/*.php`).
* **Resilient Discovery (`ConfigPathResolver`)**: The admin kernel and database migration scripts resolve database configuration dynamically by sequentially checking `/public/api/config.php` (local dev) and `/public_html/api/config.php` (production cPanel), eliminating environment-specific file hacks.

### 4. Administrative Security & Session Perimeter
* **Authentication**: Password hashes are generated via `PASSWORD_ARGON2ID` with hardened memory and iteration parameters.
* **Session Cookies**: Native PHP sessions are hardened via `SessionMiddleware` (`Secure`, `HttpOnly`, `SameSite=Lax`, name: `ovf_admin_session`) and restricted to the `admin.oceanviewflats.com` subdomain.
* **Brute-Force Defense**: Automated lockout after 5 consecutive failed login attempts tracked in `admin_users.failed_login_attempts` and `admin_users.locked_until`.
* **Immutable Audit Logging**: All mutations (reservation edits, cancellations, calendar holds, door code overrides) are recorded in `admin_audit_logs`.
* **CLI-Only User Management**: Provisioning and password rotation are restricted to the CLI runner `scripts/create-admin-user.php`, completely prohibiting web-based privilege escalation.

## Consequences

### Positive
- **Guaranteed Source Code Protection**: Physical separation of PHP source code from public webroots eliminates accidental exposure vectors.
- **Fast, Independent Releases**: Content updates to marketing pages deploy in seconds without PHP dependency checks; admin and domain model updates deploy without static site compilation.
- **Zero Business Logic Divergence**: Public booking endpoints and admin PMS tools execute identical domain models (`QuoteEngine`, `ReservationLedger`).
- **Auditability**: Complete operational traceability across all administrative state changes.

### Neutral / Operational Trade-offs
- cPanel subdomain creation requires explicit configuration to avoid defaulting to `public_html/admin`.
- Deployments require setting both `FTP_REMOTE_PATH` and optionally `FTP_ADMIN_REMOTE_PATH` in GitHub repository secrets.
- Database migrations require an intentional execution step via cPanel Terminal or SSH (`php scripts/migrate.php`).
