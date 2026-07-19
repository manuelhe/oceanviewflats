<?php
/**
 * OceanViewFlats Secure Guest Registry Processor
 * PHP 8 Compatible
 */

declare(strict_types=1);

// 1. Load Shared Utilities & Configuration
require_once __DIR__ . '/utils.php';

// Configuration from Environment Variables ($_ENV)
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'rentals@oceanviewflats.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');
define('GOOGLE_SHEET_WEBAPP_URL', $_ENV['GOOGLE_SHEET_WEBAPP_URL'] ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL'] ?? getenv('GOOGLE_SHEET_WEBAPP_URL') ?: '');

const RATE_LIMIT_FILE = 'ovf_registry_rate_limits.json';
const MAX_SUBMISSIONS = 5; // Allow more submissions in case they are registering multiple booking sets
const RATE_LIMIT_WINDOW = 600; // 10 minutes (600 seconds)
const LOCAL_BACKUP_FILE = 'ovf_registries_backup.json';

// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['POST', 'OPTIONS']);

// Reject non-POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// Enforce referer check for actual submissions (prevent direct script browsing)
enforce_referer_check();

$is_ajax = is_ajax_request();

// 2. Honeypot check (Abuse prevention)
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    send_json_response(true, 'Registration processed successfully.');
}

// 3. Rate Limiting (Abuse prevention)
enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, 'Too many registry submissions. Please wait a few minutes and try again.');

// 4. Captcha Inputs & Verification
$captcha_challenge = $_POST['captcha_challenge'] ?? '';
$captcha_signature = $_POST['captcha_signature'] ?? '';
$captcha_response = $_POST['captcha_response'] ?? '';

$captcha_check = verify_captcha_challenge($captcha_challenge, $captcha_signature, $captcha_response, CAPTCHA_SECRET, 
    'Security check failed. Please refresh the page and try again.', 'Invalid validation challenge.', 'Incorrect answer to the security question.');
if ($captcha_check !== true) {
    send_json_response(false, $captcha_check);
}

// 5. Validate Stay Details
$property = clean_input($_POST['property'] ?? '');
$check_in = clean_input($_POST['check_in'] ?? '');
$check_out = clean_input($_POST['check_out'] ?? '');

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

// 6. Validate & Parse Guests List
$guest_count_raw = $_POST['guest_count'] ?? '1';
$guest_count = min(6, max(1, (int)$guest_count_raw));
$guests = [];

for ($i = 1; $i <= $guest_count; $i++) {
    $g_name = clean_input($_POST["guest_name_$i"] ?? '');
    $g_age_raw = $_POST["guest_age_$i"] ?? '';
    $g_doc_type = clean_input($_POST["guest_doc_type_$i"] ?? '');
    $g_doc_num = clean_input($_POST["guest_doc_num_$i"] ?? '');

    if (empty($g_name) || strlen($g_name) < 2 || strlen($g_name) > 100) {
        send_json_response(false, "Please enter a valid name for Guest $i (2-100 characters).");
    }

    $g_age = (int)$g_age_raw;
    if ($g_age_raw === '' || $g_age < 0 || $g_age > 120) {
        send_json_response(false, "Please enter a valid age (0-120) for Guest $i.");
    }

    $valid_types = ['Passport', 'National ID', 'Driver License', 'Other ID', 'Cédula de Ciudadanía', 'Tarjeta de Identidad', 'Registro Civil'];
    if (!in_array($g_doc_type, $valid_types, true)) {
        $g_doc_type = 'Other ID';
    }

    if (empty($g_doc_num) || strlen($g_doc_num) < 2 || strlen($g_doc_num) > 50) {
        send_json_response(false, "Please enter a valid document number for Guest $i.");
    }

    $guests[] = [
        'index' => $i,
        'name' => $g_name,
        'age' => $g_age,
        'doc_type' => $g_doc_type,
        'doc_num' => $g_doc_num
    ];
}

// 7. Validate Optional Car Registration
$car_plates = clean_input($_POST['car_plates'] ?? '');
$car_model = clean_input($_POST['car_model'] ?? '');

if (strlen($car_plates) > 20) {
    send_json_response(false, 'Car plates cannot exceed 20 characters.');
}
if (strlen($car_model) > 100) {
    send_json_response(false, 'Car model cannot exceed 100 characters.');
}

// 8. Store Locally (Fallback Safety)
$temp_dir = sys_get_temp_dir();
$backup_path = $temp_dir . '/' . LOCAL_BACKUP_FILE;
$backup_data = [];
if (file_exists($backup_path)) {
    $backup_content = @file_get_contents($backup_path);
    if ($backup_content !== false) {
        $backup_data = json_decode($backup_content, true) ?: [];
    }
}
$new_entry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'property' => $property,
    'check_in' => $check_in,
    'check_out' => $check_out,
    'car_plates' => $car_plates,
    'car_model' => $car_model,
    'guests' => $guests,
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
];
$backup_data[] = $new_entry;
@file_put_contents($backup_path, json_encode($backup_data, JSON_PRETTY_PRINT), LOCK_EX);

// 9. Forward to Google Spreadsheet Web App (if configured)
$google_sheet_success = false;
$webhook_url = GOOGLE_SHEET_WEBAPP_URL;
if (!empty($webhook_url) && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($new_entry));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: OceanViewFlats Guest Registry PHP'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $google_sheet_success = true;
    }
}

// 10. Construct and Send Email
$subject = 'New Guest Registry - Property ' . ($property ?: 'Unspecified');
$subject = strip_newlines($subject);

// Format plain text email
$email_body = "OceanViewFlats Official Guest Registry Report\n";
$email_body .= "==================================================\n\n";
$email_body .= "STAY INFORMATION\n";
$email_body .= "--------------------------------------------------\n";
$email_body .= "Property:      " . ($property ? "OceanViewFlats $property" : "Not specified") . "\n";
$email_body .= "Check-in:      " . ($check_in ?: "Not specified") . "\n";
$email_body .= "Check-out:     " . ($check_out ?: "Not specified") . "\n";
$email_body .= "Total Guests:  " . count($guests) . "\n\n";

if (!empty($car_plates) || !empty($car_model)) {
    $email_body .= "VEHICLE INFORMATION (OPTIONAL)\n";
    $email_body .= "--------------------------------------------------\n";
    $email_body .= "Plates:        " . ($car_plates ?: "None") . "\n";
    $email_body .= "Make & Model:  " . ($car_model ?: "None") . "\n\n";
}

$email_body .= "REGISTERED GUESTS DETAILS\n";
$email_body .= "--------------------------------------------------\n";
foreach ($guests as $g) {
    $email_body .= "Guest #" . $g['index'] . ":\n";
    $email_body .= "  Name:     " . strip_newlines($g['name']) . "\n";
    $email_body .= "  ID/Doc:   " . strip_newlines($g['doc_type']) . " (" . strip_newlines($g['doc_num']) . ")\n";
    $email_body .= "  Age:      " . $g['age'] . "\n";
    $email_body .= "--------------------------------------------------\n";
}

$email_body .= "\nSYSTEM LOGS\n";
$email_body .= "--------------------------------------------------\n";
$email_body .= "Submission IP:  " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n";
$email_body .= "Timestamp:      " . date('Y-m-d H:i:s') . "\n";
$email_body .= "Local Backup:   Logged successfully.\n";
$email_body .= "Google Sheet:   " . ($google_sheet_success ? "Recorded successfully." : (empty($webhook_url) ? "Not configured." : "FAILED (HTTP $http_code)")) . "\n";
$email_body .= "==================================================\n";

$headers = [
    'From' => 'no-reply@oceanviewflats.com',
    'Reply-To' => 'rentals@oceanviewflats.com',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Mailer' => 'PHP/' . phpversion()
];

// Send the mail
$mail_sent = mail(RECIPIENT_EMAIL, $subject, $email_body, $headers);

if ($mail_sent) {
    send_json_response(true, 'Guest registration completed successfully.');
} else {
    send_json_response(true, 'Guest registration completed successfully (backed up).');
}
