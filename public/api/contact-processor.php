<?php
/**
 * OceanViewFlats Secure Contact Form Processor
 * PHP 8 Compatible
 */

declare(strict_types=1);

// 1. Load Shared Utilities & Configuration
require_once __DIR__ . '/utils.php';

// Configuration from Environment Variables ($_ENV)
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'recipe@mail.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');

const RATE_LIMIT_FILE = 'ovf_rate_limits.json';
const MAX_SUBMISSIONS = 3;
const RATE_LIMIT_WINDOW = 600; // 10 minutes (600 seconds)

// 2. Check if the request is for generating a new captcha challenge
if (isset($_GET['action']) && $_GET['action'] === 'captcha') {
    $captcha = generate_captcha_challenge(CAPTCHA_SECRET);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($captcha);
    exit;
}

// 3. Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

$is_ajax = is_ajax_request();

// 4. Honeypot check (Abuse prevention)
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    if ($is_ajax) {
        send_json_response(true, 'Message sent successfully.');
    } else {
        header('Location: contact/index.html?success=1');
        exit;
    }
}

// 5. Rate Limiting (Abuse prevention)
enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, 'Too many requests. Please wait a few minutes and try again.');

// 6. Input Validation & Sanitization
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

// Verify Captcha
$captcha_check = verify_captcha_challenge($captcha_challenge, $captcha_signature, $captcha_response, CAPTCHA_SECRET);
if ($captcha_check !== true) {
    send_json_response(false, $captcha_check);
}

// Sanitize inputs using shared utils
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

// 7. Safe Email Construction
$clean_name = strip_newlines($name);
$clean_email = strip_newlines($email);
$clean_phone = strip_newlines($full_phone);

$subject = 'Inquiry from ' . $clean_name . ' - OceanViewFlats';
$subject = strip_newlines($subject);

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
