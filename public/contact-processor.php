<?php
/**
 * OceanViewFlats Secure Contact Form Processor
 * PHP 8 Compatible
 */

declare(strict_types=1);

// Configuration from Environment Variables ($_ENV)
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'recipe@mail.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');

const RATE_LIMIT_FILE = 'ovf_rate_limits.json';
const MAX_SUBMISSIONS = 3;
const RATE_LIMIT_WINDOW = 600; // 10 minutes (600 seconds)

// Helper function to send JSON response
function send_json_response(bool $success, string $message): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

// Helper to clean and sanitize string inputs
function clean_input(string $data): string {
    $data = trim($data);
    $data = stripslashes($data);
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Helper to strip newlines (prevents email header injection)
function strip_newlines(string $str): string {
    return str_replace(["\r", "\n", "%0a", "%0d"], '', $str);
}

// 1. Check if the request is for generating a new captcha challenge
if (isset($_GET['action']) && $_GET['action'] === 'captcha') {
    $x = random_int(2, 9);
    $y = random_int(2, 9);
    $challenge = "$x + $y";
    $signature = hash_hmac('sha256', $challenge, CAPTCHA_SECRET);
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'challenge' => $challenge,
        'signature' => $signature
    ]);
    exit;
}

// 2. Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// Detect if AJAX request
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
    || (isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// 3. Honeypot check (Abuse prevention)
// Spambots usually fill out all fields. website_url is hidden from humans via CSS.
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    // Drop request silently (pretend it was successful to trick the bot)
    if ($is_ajax) {
        send_json_response(true, 'Message sent successfully.');
    } else {
        header('Location: contact/index.html?success=1');
        exit;
    }
}

// 4. Rate Limiting (Abuse prevention)
// Store submission timestamps hashed by IP to protect user privacy
$temp_dir = sys_get_temp_dir();
$rate_limit_path = $temp_dir . '/' . RATE_LIMIT_FILE;
$ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
$now = time();

$limits = [];
if (file_exists($rate_limit_path)) {
    $file_content = file_get_contents($rate_limit_path);
    if ($file_content !== false) {
        $limits = json_decode($file_content, true) ?: [];
    }
}

// Clean up old rate limit entries
foreach ($limits as $hash => $timestamps) {
    $filtered = array_filter($timestamps, function($ts) use ($now) {
        return ($now - $ts) < RATE_LIMIT_WINDOW;
    });
    if (empty($filtered)) {
        unset($limits[$hash]);
    } else {
        $limits[$hash] = array_values($filtered);
    }
}

// Check rate limit for current IP
if (isset($limits[$ip_hash]) && count($limits[$ip_hash]) >= MAX_SUBMISSIONS) {
    http_response_code(429);
    send_json_response(false, 'Too many requests. Please wait a few minutes and try again.');
}

// 5. Input Validation & Sanitization
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$phone_country_code = $_POST['phone_country_code'] ?? '';
$phone_number = $_POST['phone_number'] ?? '';
$check_in = $_POST['check_in'] ?? '';
$check_out = $_POST['check_out'] ?? '';
$message = $_POST['message'] ?? '';

// Captcha inputs
$captcha_challenge = $_POST['captcha_challenge'] ?? '';
$captcha_signature = $_POST['captcha_signature'] ?? '';
$captcha_response = $_POST['captcha_response'] ?? '';

// Validate Captcha Signature
$expected_signature = hash_hmac('sha256', $captcha_challenge, CAPTCHA_SECRET);
if (!hash_equals($expected_signature, $captcha_signature)) {
    send_json_response(false, 'Security check failed. Please refresh the page and try again.');
}

// Verify Captcha Math Answer
if (!preg_match('/^(\d+)\s*\+\s*(\d+)$/', $captcha_challenge, $matches)) {
    send_json_response(false, 'Invalid captcha validation challenge.');
}
$expected_sum = (int)$matches[1] + (int)$matches[2];
if ((int)$captcha_response !== $expected_sum) {
    send_json_response(false, 'Incorrect answer to the math problem.');
}

// Sanitize inputs
$name = clean_input($name);
$email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
$phone_country_code = preg_replace('/[^\+\d]/', '', $phone_country_code);
$phone_number = preg_replace('/[^\d\s\-\(\)]/', '', $phone_number);
$message = clean_input($message);

if (empty($name) || strlen($name) < 2 || strlen($name) > 100) {
    send_json_response(false, 'Please enter a valid name (2-100 characters).');
}

if (!$email) {
    send_json_response(false, 'Please enter a valid email address.');
}

if (empty($phone_number) || strlen($phone_number) < 6 || strlen($phone_number) > 20) {
    send_json_response(false, 'Please enter a valid phone number.');
}

$full_phone = $phone_country_code . ' ' . $phone_number;

// Validate optional dates
$dates_provided = !empty($check_in) || !empty($check_out);
if (!empty($check_in)) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in) || strtotime($check_in) === false) {
        send_json_response(false, 'Invalid check-in date format.');
    }
}
if (!empty($check_out)) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out) || strtotime($check_out) === false) {
        send_json_response(false, 'Invalid check-out date format.');
    }
}
if (!empty($check_in) && !empty($check_out)) {
    if (strtotime($check_in) >= strtotime($check_out)) {
        send_json_response(false, 'Check-out date must be after check-in date.');
    }
}

if (empty($message) || strlen($message) < 10) {
    send_json_response(false, 'Message must be at least 10 characters.');
}
if (strlen($message) > 5000) {
    send_json_response(false, 'Message is too long (max 5000 characters).');
}

// Add current timestamp to rate limits list and save
$limits[$ip_hash][] = $now;
file_put_contents($rate_limit_path, json_encode($limits), LOCK_EX);

// 6. Safe Email Construction
$clean_name = strip_newlines($name);
$clean_email = strip_newlines($email);
$clean_phone = strip_newlines($full_phone);

$subject = 'Inquiry from ' . $clean_name . ' - OceanViewFlats';
$subject = strip_newlines($subject);

// Format plain text message body to avoid any scripting payload executions
$email_body = "You have received a new inquiry from the OceanViewFlats website.\n\n";
$email_body .= "--------------------------------------------------\n";
$email_body .= "Name:       $clean_name\n";
$email_body .= "Email:      $clean_email\n";
$email_body .= "Phone:      $clean_phone\n";
if (!empty($check_in)) {
    $email_body .= "Check-in:   $check_in\n";
}
if (!empty($check_out)) {
    $email_body .= "Check-out:  $check_out\n";
}
$email_body .= "--------------------------------------------------\n\n";
$email_body .= "Message:\n$message\n\n";
$email_body .= "--------------------------------------------------\n";
$email_body .= "IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";

// Safer Mail headers array (supported in PHP 8)
$headers = [
    'From' => 'no-reply@oceanviewflats.com',
    'Reply-To' => "$clean_name <$clean_email>",
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Mailer' => 'PHP/' . phpversion()
];

// Send email
$success = mail(RECIPIENT_EMAIL, $subject, $email_body, $headers);

if ($success) {
    if ($is_ajax) {
        send_json_response(true, 'Message sent successfully.');
    } else {
        $referer = $_SERVER['HTTP_REFERER'] ?? 'contact/index.html';
        $redirect = strtok($referer, '?') . '?success=1';
        header("Location: $redirect");
        exit;
    }
} else {
    http_response_code(500);
    send_json_response(false, 'Unable to send message. Please try again later.');
}
