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

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OceanViewFlats\Domain\Fulfillment\BookingFulfillment;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;

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
            SET `status` = 'confirmed', 
                `mercadopago_payment_id` = :pay_id, 
                `payment_status` = :pay_status, 
                `payment_method_id` = :pay_method, 
                `updated_at` = NOW() 
            WHERE `reservation_uid` = :uid AND `status` != 'confirmed'
        ");
        $upStmt->execute([
            'uid' => $uid,
            'pay_id' => $paymentId,
            'pay_status' => $status,
            'pay_method' => $paymentData['payment_method_id'] ?? ''
        ]);
        
        $pdo->commit();
        log_webhook_message("Reservation {$uid} successfully CONFIRMED in local MySQL database.");
    } catch (Exception $txEx) {
        $pdo->rollBack();
        log_webhook_message("Transaction failed: " . $txEx->getMessage());
        http_response_code(500);
        exit("Database update failed.");
    }

    // 9. Post-settlement fulfillment via authoritative BookingFulfillment service
    $confirmedReservation = new Reservation(
        reservationUid: (string)$reservation['reservation_uid'],
        propertyId: (string)$reservation['property_id'],
        guestName: (string)$reservation['guest_name'],
        guestEmail: (string)$reservation['guest_email'],
        guestPhone: (string)$reservation['guest_phone'],
        checkIn: (string)$reservation['check_in'],
        checkOut: (string)$reservation['check_out'],
        totalPrice: (float)$reservation['total_price'],
        status: ReservationStatus::CONFIRMED,
        paymentMethodId: $paymentData['payment_method_id'] ?? ($reservation['payment_method_id'] ?? null),
        mercadopagoPaymentId: (string)$paymentId,
        paymentStatus: $status,
        lang: $reservation['lang'] ?? null,
        createdAt: new DateTimeImmutable($reservation['created_at'] ?? 'now')
    );

    $fulfillment = BookingFulfillment::createDefault();
    $fulfillmentResult = $fulfillment->fulfillConfirmation($confirmedReservation, [
        'payment_id' => $paymentId,
        'payment_status' => $status
    ]);

    if ($fulfillmentResult->isSuccess()) {
        log_webhook_message("Confirmation fulfillment completed successfully for {$uid}.");
    } else {
        log_webhook_message("Fulfillment completed with notices: " . implode('; ', $fulfillmentResult->errors));
    }
} else {
    // If status is not approved, log the status
    log_webhook_message("Payment status is '{$status}' (not approved). No action taken.");
}

// 11. Respond to MercadoPago with standard OK code (200) to stop webhook retries
http_response_code(200);
echo json_encode(["success" => true, "status" => $status]);
