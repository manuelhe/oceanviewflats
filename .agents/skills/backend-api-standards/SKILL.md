---
name: backend-api-standards
description: Enforces secure coding guidelines and architectural standards for OceanViewFlats backend PHP REST APIs. Use when creating, modifying, refactoring, or auditing PHP files inside the public/api/ folder.
---

# Backend API Coding Standards

You are an expert PHP and application security engineer. Your objective is to guide and audit modifications, creations, or refactorings of PHP scripts inside the `public/api/` folder. All scripts must adhere to strict security, performance, and localization guidelines.

---

## 🏗️ Core API Architecture

All active API endpoints reside under `public/api/`. They are configured as standalone transaction scripts that share common components and security modules.

### 1. Mandatory File Headers
Every backend script must use modern PHP 8 strict typing and require the shared utility library:
```php
<?php
/**
 * OceanViewFlats Secure [Form/Action] Processor
 * PHP 8 Compatible
 */

declare(strict_types=1);

// Load Shared Utilities & Configuration
require_once __DIR__ . '/utils.php';

// Load Unified Translations Dictionary
$all_translations = require __DIR__ . '/translations.php';
```

---

## 🔒 Security Requirements

### 1. CORS Enforcements & Security Headers
Every entry point must invoke the shared CORS and Security Headers dispatcher before executing any other logic:
```php
// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['POST', 'OPTIONS']); // Limit allowed methods strictly
```

### 2. Browser Referer Validations
All user-facing forms (e.g., Contacts, Registry, Bookings) must enforce referer checking to block browser bar submissions or unauthorized cross-site submissions:
```php
// Enforce valid referer origin (prevents direct address bar typing)
enforce_referer_check();
```

### 3. Input Sanitization
Never trust user inputs. Always sanitize string variables using the shared `clean_input()` subroutine to neutralize Cross-Site Scripting (XSS):
```php
$name = clean_input($_POST['name'] ?? '');
$email = clean_input($_POST['email'] ?? '');
```

### 4. Mathematical CAPTCHA Verification
All user-facing transactional scripts must employ mathematical CAPTCHAs. Generate the challenge in your setup phase, and verify it prior to executing queries or emails:
```php
// Verification
$captcha_check = verify_captcha_challenge(
    $captcha_challenge, 
    $captcha_signature, 
    $captcha_response, 
    CAPTCHA_SECRET,
    $all_translations[$lang]['booking']['err_captcha_sign'],
    $all_translations[$lang]['booking']['err_captcha_invalid'],
    $all_translations[$lang]['booking']['err_captcha_wrong']
);

if ($captcha_check !== true) {
    send_json_response(false, $captcha_check);
}
```

### 5. Rate Limiting
Inject file-backed rate-limiting to prevent brute force or Denial of Service (DoS) attacks on endpoints:
```php
const RATE_LIMIT_FILE = 'ovf_my_endpoint_limits.json';
const MAX_SUBMISSIONS = 5;
const RATE_LIMIT_WINDOW = 600; // 10 minutes

// Enforce limit
enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, $t['err_rate_limit']);
```

---

## 🌐 Dynamic Localization

No text strings (including validation messages, database logs, or emails) may be hardcoded. 
*   **Resolution**: Always retrieve the user's language preference using the validated whitelister:
    ```php
    $lang = get_validated_lang();
    $t = $all_translations[$lang]['context_key'] ?? $all_translations['en']['context_key'];
    ```
*   **Translation Keys**: Ensure any new keys exist for all 6 supported languages (`en`, `es`, `fr`, `it`, `de`, `ja`) inside `public/api/translations.php`.

---

## 🛢️ Database Operations

When accessing the database:
*   Standardize PDO handle instantiation via the shared factory:
    ```php
    $pdo = get_db_connection($dbConfig);
    ```
*   Always employ **Prepared SQL Statements** with named parameters. Never interpolate variables directly into SQL statements.
*   For critical updates (like booking holds), wrap queries in **Database Transactions** to ensure thread safety and lock holds properly:
    ```php
    $pdo->beginTransaction();
    try {
        // Run select FOR UPDATE to acquire lock
        // Execute inserts/updates
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json_response(false, 'Transaction failed.');
    }
    ```

---

## 🚀 Quality Assurance Workflow

Before marking any backend change complete:
1.  Verify the PHP syntax using the command line linter:
    ```bash
    php -l public/api/your-file.php
    ```
2.  Ensure there are no trailing whitespaces or syntax warnings.
3.  Check that `.htaccess` restricts direct access to any support helper files you introduce (e.g., config templates).
