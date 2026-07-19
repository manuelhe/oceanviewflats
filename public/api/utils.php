<?php
/**
 * OceanViewFlats - Shared API Utility Library
 * PHP 8 Compatible
 * 
 * Unifies core reusable subroutines: Database PDO initialization, inputs sanitization,
 * response dispatching, rate limiting, and mathematical captcha generation & verification.
 */

declare(strict_types=1);

/**
 * Centrally manages the database connection using PDO.
 * 
 * @param array $dbConfig Database credentials mapping.
 * @return PDO Established database connection handle.
 */
function get_db_connection(array $dbConfig): PDO {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    return new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Cleans and sanitizes string inputs to prevent Cross-Site Scripting (XSS).
 * 
 * @param string $data Raw input string.
 * @return string Cleaned and safe sanitization string.
 */
function clean_input(string $data): string {
    return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Strips carriage returns and newlines to prevent email header injection attacks.
 * 
 * @param string $str Raw header input.
 * @return string Stripped line safe for headers.
 */
function strip_newlines(string $str): string {
    return str_replace(["\r", "\n", "%0a", "%0d"], '', $str);
}

/**
 * Dispatches a standard JSON response to the client and terminates execution.
 * 
 * @param bool $success Operation status.
 * @param string $message Narrative text response.
 * @param array $extra Optional key-value pairs to merge into response.
 */
function send_json_response(bool $success, string $message, array $extra = []): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $res = array_merge(['success' => $success], $extra);
    if ($success) {
        $res['message'] = $message;
    } else {
        $res['error'] = $message;
    }
    echo json_encode($res);
    exit;
}

/**
 * Detects if the current request is an AJAX/XMLHttpRequest request.
 * 
 * @return bool True if AJAX, false otherwise.
 */
function is_ajax_request(): bool {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
        || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
}

/**
 * Generates a secure HMAC-signed mathematical captcha challenge.
 * 
 * @param string $secret Encryption salt secret.
 * @return array Challenge formula and HMAC signature.
 */
function generate_captcha_challenge(string $secret): array {
    $x = random_int(2, 9);
    $y = random_int(2, 9);
    $challenge = "$x + $y";
    $signature = hash_hmac('sha256', $challenge, $secret);
    return [
        'challenge' => $challenge,
        'signature' => $signature
    ];
}

/**
 * Verifies the mathematical captcha solution.
 * 
 * @param string $challenge Challenged arithmetic equation.
 * @param string $signature HMCA authentication signature.
 * @param string $response Provided user response value.
 * @param string $secret HMAC encryption salt.
 * @param string $errorSign Custom error signature failure text.
 * @param string $errorInvalid Custom invalid formula format text.
 * @param string $errorWrong Custom incorrect math sum text.
 * @return bool|string True if valid, or the matched error message string on failure.
 */
function verify_captcha_challenge(
    string $challenge, 
    string $signature, 
    string $response, 
    string $secret, 
    string $errorSign = 'Security check failed. Please refresh the page and try again.', 
    string $errorInvalid = 'Invalid verification challenge.', 
    string $errorWrong = 'Incorrect answer.'
) {
    $expected_signature = hash_hmac('sha256', $challenge, $secret);
    if (!hash_equals($expected_signature, $signature)) {
        return $errorSign;
    }
    if (!preg_match('/^(\d+)\s*\+\s*(\d+)$/', $challenge, $matches)) {
        return $errorInvalid;
    }
    $expected_sum = (int)$matches[1] + (int)$matches[2];
    if ((int)$response !== $expected_sum) {
        return $errorWrong;
    }
    return true;
}

/**
 * Enforces IP-based rate-limiting with a file-backed storage inside sys_get_temp_dir().
 * 
 * @param string $filename Local storage JSON filename.
 * @param int $maxSubmissions Max requests permitted in the window.
 * @param int $windowSeconds Expiration window timer in seconds.
 * @param string $errorMessage Localized rate limiting message.
 */
function enforce_rate_limit(string $filename, int $maxSubmissions, int $windowSeconds, string $errorMessage = 'Too many requests. Please wait a few minutes and try again.'): void {
    $temp_dir = sys_get_temp_dir();
    $rate_limit_path = $temp_dir . '/' . $filename;
    $ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $now = time();

    $limits = [];
    if (file_exists($rate_limit_path)) {
        $file_content = @file_get_contents($rate_limit_path);
        if ($file_content !== false) {
            $limits = json_decode($file_content, true) ?: [];
        }
    }

    // Clean up old rate limit entries
    foreach ($limits as $hash => $timestamps) {
        $filtered = array_filter($timestamps, function($ts) use ($now, $windowSeconds) {
            return ($now - $ts) < $windowSeconds;
        });
        if (empty($filtered)) {
            unset($limits[$hash]);
        } else {
            $limits[$hash] = array_values($filtered);
        }
    }

    // Check rate limit
    if (isset($limits[$ip_hash]) && count($limits[$ip_hash]) >= $maxSubmissions) {
        http_response_code(429);
        send_json_response(false, $errorMessage);
    }

    // Append current timestamp and save back
    $limits[$ip_hash][] = $now;
    @file_put_contents($rate_limit_path, json_encode($limits), LOCK_EX);
}
