<?php
/**
 * OceanViewFlats - MercadoPago Webhook / IPN Listener Endpoint
 * 
 * listents to real-time status updates from MercadoPago Checkout Pro,
 * performs server-to-server validation to avoid spoofing, updates MySQL DB states,
 * syncs with Google Sheets webhook, and triggers fully localized guest confirmation emails.
 */

// 1. Prevent direct execution if included
if (count(get_included_files()) === 1 && !defined('ALLOW_WEBHOOK_RUN')) {
    define('ALLOW_WEBHOOK_RUN', true);
}

// 2. Load Shared Utilities & Configuration
require_once __DIR__ . '/utils.php';

// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['GET', 'POST', 'OPTIONS']);

// Enforce rate-limit to prevent webhook flooding or IPN bruteforcing
enforce_rate_limit('ovf_webhook_rate_limits.json', 150, 600, 'Too many requests. Please wait a few minutes and try again.');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit("Config missing");
}
$config = require $configPath;

// 3. Setup Error Logging
function log_webhook_message(string $msg): void {
    error_log("[MercadoPago Webhook] " . $msg);
}

// 4. Extract Payment ID (Support Webhooks & IPNs gracefully)
$paymentId = null;
$topic = null;

// Parse incoming raw JSON
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

if (!empty($inputData)) {
    if (isset($inputData['type']) && $inputData['type'] === 'payment') {
        $paymentId = $inputData['data']['id'] ?? null;
    } elseif (isset($inputData['topic']) && $inputData['topic'] === 'payment') {
        $paymentId = $inputData['id'] ?? null;
    }
}

// Fallback to Query Parameters (IPNs)
if (!$paymentId) {
    $paymentId = $_GET['id'] ?? $_GET['data_id'] ?? null;
    $topic = $_GET['topic'] ?? null;
}

if (!$paymentId) {
    // Keep it silent for status checks or empty hits
    http_response_code(200);
    echo json_encode(["success" => true, "message" => "Webhook initialized. Waiting for payment data."]);
    exit;
}

log_webhook_message("Processing payload for Payment ID: " . $paymentId);

// 5. Load Access Token & Initialize Database Connection
$mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
if (empty($mpAccessToken)) {
    log_webhook_message("CRITICAL: MERCADOPAGO_ACCESS_TOKEN environment variable not set.");
    http_response_code(500);
    exit("Server configuration error.");
}

try {
    $pdo = get_db_connection($config['db']);
} catch (PDOException $e) {
    log_webhook_message("Database offline: " . $e->getMessage());
    http_response_code(503);
    exit("Service Temporarily Unavailable.");
}

// 6. Server-to-Server Verification (Bulletproof protection against spoofing)
$ch = curl_init("https://api.mercadopago.com/v1/payments/" . $paymentId);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $mpAccessToken,
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$responseStr = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    log_webhook_message("Verification query failed with code: " . $httpCode . " Response: " . $responseStr);
    http_response_code(400);
    exit("Verification failed.");
}

$paymentData = json_decode($responseStr, true);
if (empty($paymentData)) {
    log_webhook_message("Empty verification payload.");
    http_response_code(400);
    exit("Invalid payment data.");
}

// 7. Inspect transaction status
$status = $paymentData['status'] ?? '';
$statusDetail = $paymentData['status_detail'] ?? '';
$uid = $paymentData['external_reference'] ?? ''; // This is our reservation_uid

log_webhook_message("Verified Payment ID: {$paymentId}. Status: {$status}. Detail: {$statusDetail}. Reservation: {$uid}");

if (empty($uid)) {
    log_webhook_message("Ignored: payment is not associated with an OceanViewFlats reservation reference.");
    http_response_code(200);
    exit("OK (No OVF Reference)");
}

// Find matching reservation in local MySQL
$stmt = $pdo->prepare("SELECT * FROM `reservations` WHERE `reservation_uid` = :uid LIMIT 1");
$stmt->execute(['uid' => $uid]);
$reservation = $stmt->fetch();

if (!$reservation) {
    log_webhook_message("Ignored: Reference reservation {$uid} not found in database.");
    http_response_code(200);
    exit("OK (Reservation Not Found)");
}

// 8. If status is approved, confirm booking and trigger emails
if ($status === 'approved') {
    if ($reservation['status'] === 'confirmed') {
        log_webhook_message("Ignored: Reservation {$uid} is already confirmed.");
        http_response_code(200);
        exit("OK (Already Confirmed)");
    }

    // Begin Database Transaction to update state safely
    try {
        $pdo->beginTransaction();
        
        $upStmt = $pdo->prepare("
            UPDATE `reservations` 
            SET `status` = 'confirmed', `updated_at` = NOW() 
            WHERE `reservation_uid` = :uid AND `status` != 'confirmed'
        ");
        $upStmt->execute(['uid' => $uid]);
        
        $pdo->commit();
        log_webhook_message("Reservation {$uid} successfully CONFIRMED in local MySQL database.");
    } catch (Exception $txEx) {
        $pdo->rollBack();
        log_webhook_message("Transaction failed: " . $txEx->getMessage());
        http_response_code(500);
        exit("Database update failed.");
    }

    // 9. Sync Confirmation state with Google Sheets
    $webhook_url = $_ENV['GOOGLE_SHEET_WEBAPP_URL'] ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL'] ?? getenv('GOOGLE_SHEET_WEBAPP_URL') ?: '';
    if (!empty($webhook_url) && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        $sheetPayload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'reservation_uid' => $uid,
            'property' => $reservation['property_id'],
            'check_in' => $reservation['check_in'],
            'check_out' => $reservation['check_out'],
            'guest_name' => $reservation['guest_name'],
            'guest_email' => $reservation['guest_email'],
            'guest_phone' => $reservation['guest_phone'],
            'total_price' => $reservation['total_price'],
            'status' => 'confirmed',
            'payment_id' => $paymentId
        ];

        $ch = curl_init($webhook_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sheetPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: OceanViewFlats Direct Booking PHP Webhook'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_exec($ch);
        curl_close($ch);
    }

    // 10. Compile and Dispatch Localized Guest Confirmation Receipt Email
    // Detect language preference of guest
    $guestEmail = $reservation['guest_email'];
    $guestName = $reservation['guest_name'];
    $propertyId = $reservation['property_id'];
    $checkIn = $reservation['check_in'];
    $checkOut = $reservation['check_out'];
    $totalPrice = $reservation['total_price'];
    
    // Load language preference from the database record, fallback to email deduction
    $lang = $reservation['lang'] ?? '';
    if (is_array($lang)) {
        $lang = '';
    }
    $lang = htmlspecialchars(trim(stripslashes((string)$lang)), ENT_QUOTES, 'UTF-8');
    if (empty($lang) || !in_array($lang, ['en', 'es', 'fr', 'it', 'de', 'ja'], true)) {
        $lang = (strpos($guestEmail, '.cl') !== false || strpos($guestEmail, '.ar') !== false || strpos($guestEmail, '.co') !== false || strpos($guestEmail, '.mx') !== false || strpos($guestEmail, '.es') !== false) ? 'es' : 'en';
    }

    $all_translations = require __DIR__ . '/translations.php';
    $t = $all_translations[$lang]['webhook'] ?? $all_translations['en']['webhook'];

    $formattedTotal = "$ " . number_format((float)$totalPrice, 0, ',', '.') . " COP";
    
    // Calculate nights count
    $nights = (int)round((strtotime($checkOut) - strtotime($checkIn)) / 86400);

    // Confirmation Email HTML
    $html_message = "
    <html>
    <head>
      <style>
        body { font-family: sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
        .header { background-color: #0f172a; padding: 24px; text-align: center; color: #ffffff; }
        .body { padding: 24px; }
        .card { background-color: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .table { width: 100%; margin-top: 10px; border-collapse: collapse; }
        .table td { padding: 8px 0; border-bottom: 1px solid #edf2f7; }
        .footer { font-size: 11px; color: #999; padding: 20px; text-align: center; border-top: 1px solid #eee; }
        .btn { display: inline-block; background-color: #0d9488; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: bold; margin-top: 15px; }
      </style>
    </head>
    <body>
      <div class='container'>
        <div class='header'>
          <h2 style='margin:0;color:#ffffff;'>{$t['title']}</h2>
          <p style='margin:5px 0 0 0;color:#94a3b8;'>OceanViewFlats Santa Marta</p>
        </div>
        <div class='body'>
          <p>" . sprintf($t['intro'], $guestName) . "</p>
          <p>{$t['desc']}</p>
          
          <div class='card'>
            <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>{$t['summary']}</h3>
            <table width='100%' class='table'>
              <tr><td><strong>{$t['property']}:</strong></td><td class='text-right'>OceanViewFlats {$propertyId}</td></tr>
              <tr><td><strong>{$t['code']}:</strong></td><td class='text-right'><code style='background:#f1f5f9;padding:2px 6px;border-radius:4px;'>{$uid}</code></td></tr>
              <tr><td><strong>Check-In:</strong></td><td class='text-right'>{$checkIn}</td></tr>
              <tr><td><strong>Check-Out:</strong></td><td class='text-right'>{$checkOut}</td></tr>
              <tr><td><strong>{$t['nights']}:</strong></td><td class='text-right'>" . sprintf($t['nights_val'], $nights) . "</td></tr>
              <tr style='font-size:16px;font-weight:bold;'><td style='border-bottom:none;'>{$t['total']}:</td><td class='text-right' style='border-bottom:none;color:#0d9488;'>{$formattedTotal}</td></tr>
            </table>
          </div>

          <p>{$t['footer']}</p>
          
          <div style='text-align: center;'>
            <a href='https://www.oceanviewflats.com/guide/?code={$uid}' class='btn' style='color:#ffffff;'>{$t['btn_guide']}</a>
          </div>
        </div>
        <div class='footer'>
          &copy; 2026 OceanViewFlats. Calle 26 # 2-80, Playa Salguero, Santa Marta, Colombia.
        </div>
      </div>
    </body>
    </html>
    ";

    // Set MIME headers
    $recipientEmail = $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'rentals@oceanviewflats.com';
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: OceanViewFlats <no-reply@oceanviewflats.com>\r\n";
    $headers .= "Reply-To: rentals@oceanviewflats.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // Send to guest
    $subjectGuest = sprintf($t['subject'], $propertyId);
    mail($guestEmail, $subjectGuest, $html_message, $headers);

    // Send copy to host
    $subjectHost = "DIRECT BOOKING PAID APPROVED: Prop $propertyId ($guestName) - [CONFIRMED]";
    mail($recipientEmail, $subjectHost, $html_message, $headers);
    
    log_webhook_message("Confirmation and receipt emails dispatched successfully to {$guestEmail} and host.");
} else {
    // If status is not approved, log the status
    log_webhook_message("Payment status is '{$status}' (not approved). No action taken.");
}

// 11. Respond to MercadoPago with standard OK code (200) to stop webhook retries
http_response_code(200);
echo json_encode(["success" => true, "status" => $status]);
