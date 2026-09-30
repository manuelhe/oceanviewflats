<?php
/**
 * OceanViewFlats Secure Guest Registry Processor
 * PHP 8 Compatible HTTP Adapter
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/utils.php';

use OceanViewFlats\Domain\Fulfillment\GuestLifecycleFulfillmentService;
use OceanViewFlats\Domain\Fulfillment\GuestLifecycleFulfillmentServiceInterface;
use OceanViewFlats\Domain\Fulfillment\GuestRegistrySubmission;

// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['POST', 'OPTIONS']);

// Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// Enforce referer check for actual submissions (prevent direct script browsing)
enforce_referer_check();

// Read input from request body with CLI STDIN support and $_POST fallback
$rawInput = file_get_contents('php://input');
if (($rawInput === false || $rawInput === '') && PHP_SAPI === 'cli' && defined('STDIN')) {
    $rawInput = @stream_get_contents(STDIN);
}
$data = !empty($rawInput) ? json_decode((string) $rawInput, true) : [];
if (!is_array($data)) {
    $data = [];
}
if (!empty($_POST)) {
    $data = array_merge($_POST, $data);
}
if (empty($data['ip_address']) && isset($_SERVER['REMOTE_ADDR'])) {
    $data['ip_address'] = $_SERVER['REMOTE_ADDR'];
}

// Sanitize input array
$sanitizedData = [];
foreach ($data as $key => $val) {
    if (is_string($val)) {
        $sanitizedData[$key] = clean_input($val);
    } elseif (is_array($val)) {
        $sanitizedData[$key] = array_map(function ($item) {
            if (is_array($item)) {
                return array_map(fn ($v) => is_string($v) ? clean_input($v) : $v, $item);
            }
            return is_string($item) ? clean_input($item) : $item;
        }, $val);
    } else {
        $sanitizedData[$key] = $val;
    }
}
$data = $sanitizedData;

// Validate language and load translations
$lang = get_validated_lang((string) ($data['lang'] ?? ''));
$allTranslations = require __DIR__ . '/translations.php';
$t = $allTranslations[$lang]['registry'] ?? $allTranslations['en']['registry'];
$successMessage = $t['msg_success'] ?? 'Registration successfully processed.';

// Honeypot check (Abuse prevention)
if (!empty($data['website_hp']) || !empty($data['website_url'])) {
    send_json_response(true, $successMessage);
}

// Rate Limiting (Abuse prevention)
const RATE_LIMIT_FILE = 'ovf_registry_rate_limits.json';
const MAX_SUBMISSIONS = 5;
const RATE_LIMIT_WINDOW = 600; // 10 minutes

enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, $t['err_rate_limit'] ?? 'Too many requests. Please wait a few minutes and try again.');

// Mathematical CAPTCHA Verification
$captchaSecret = (string) ($_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');
$captchaCheck = verify_captcha_challenge(
    clean_input((string) ($data['captcha_challenge'] ?? '')),
    clean_input((string) ($data['captcha_signature'] ?? '')),
    clean_input((string) ($data['captcha_response'] ?? '')),
    $captchaSecret,
    $allTranslations[$lang]['booking']['err_captcha_sign'] ?? 'Security check failed. Please refresh the page and try again.',
    $allTranslations[$lang]['booking']['err_captcha_invalid'] ?? 'Invalid verification challenge.',
    $allTranslations[$lang]['booking']['err_captcha_wrong'] ?? 'Incorrect answer to the security verification question.'
);
if ($captchaCheck !== true) {
    send_json_response(false, $captchaCheck);
}

// Database & Service Resolution
if (isset($GLOBALS['TEST_LIFECYCLE_SERVICE']) && $GLOBALS['TEST_LIFECYCLE_SERVICE'] instanceof GuestLifecycleFulfillmentServiceInterface) {
    $service = $GLOBALS['TEST_LIFECYCLE_SERVICE'];
} else {
    if (isset($GLOBALS['TEST_PDO']) && $GLOBALS['TEST_PDO'] instanceof PDO) {
        $pdo = $GLOBALS['TEST_PDO'];
    } else {
        $config = require __DIR__ . '/config.php';
        $pdo = get_db_connection($config['db']);
    }
    $service = GuestLifecycleFulfillmentService::createDefault($pdo);
}

try {
    $submission = GuestRegistrySubmission::fromArray($data);
    $result = $service->submitRegistry($submission);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    send_json_response(false, $e->getMessage(), [
        'errors' => [$e->getMessage()],
    ]);
}

if (!$result->success) {
    $firstError = !empty($result->errors) ? $result->errors[0] : 'Validation failed';
    http_response_code(400);
    send_json_response(false, $firstError, [
        'errors' => $result->errors,
    ]);
}

http_response_code(200);
send_json_response(true, $successMessage, [
    'door_code' => $result->doorCode,
    'guide_url' => $result->guideUrl,
    'reservation_code' => $result->reservation->reservationUid ?? $submission->reservationCode,
]);
