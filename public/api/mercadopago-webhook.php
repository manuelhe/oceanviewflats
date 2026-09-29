<?php
/**
 * OceanViewFlats - MercadoPago Webhook / IPN Listener Endpoint
 * 
 * Listens to real-time status updates from MercadoPago Checkout Pro,
 * performs server-to-server validation to avoid spoofing, updates DB states,
 * syncs with Google Sheets webhook, and triggers fully localized guest confirmation emails.
 */

declare(strict_types=1);

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

// Enforce rate-limit to prevent webhook flooding or IPN bruteforcing (unless testing)
if (empty($GLOBALS['DISABLE_RATE_LIMIT'])) {
    enforce_rate_limit('ovf_webhook_rate_limits.json', 150, 600, 'Too many requests. Please wait a few minutes and try again.');
}

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath) && !isset($GLOBALS['TEST_PDO'])) {
    http_response_code(500);
    exit("Config missing");
}
$config = file_exists($configPath) ? require $configPath : ['db' => []];

// 3. Setup Error Logging
if (!function_exists('log_webhook_message')) {
    function log_webhook_message(string $msg): void {
        error_log("[MercadoPago Webhook] " . $msg);
    }
}

/**
 * Synchronizes external refund line items into reservation_refunds.
 *
 * @param PDO $pdo
 * @param string $uid
 * @param string $paymentId
 * @param array<int, mixed> $refundsList
 * @param float $fallbackAmount
 * @param string $source
 */
if (!function_exists('sync_refund_items')) {
    function sync_refund_items(
        PDO $pdo,
        string $uid,
        string $paymentId,
        array $refundsList,
        float $fallbackAmount,
        string $source = 'mercadopago_webhook'
    ): void {
        if (empty($refundsList)) {
            if ($fallbackAmount > 0) {
                try {
                    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM reservation_refunds WHERE reservation_uid = :uid');
                    $checkStmt->execute(['uid' => $uid]);
                    if ((int) $checkStmt->fetchColumn() === 0) {
                        $ins = $pdo->prepare('
                            INSERT INTO reservation_refunds (
                                reservation_uid, mercadopago_refund_id, mercadopago_payment_id,
                                amount, status, reason, source, admin_user_id, created_at
                            ) VALUES (
                                :uid, NULL, :payment_id, :amount, "approved", "External refund synchronized via webhook", :source, NULL, CURRENT_TIMESTAMP
                            )
                        ');
                        $ins->execute([
                            'uid' => $uid,
                            'payment_id' => $paymentId !== '' ? $paymentId : 'external_webhook',
                            'amount' => $fallbackAmount,
                            'source' => $source,
                        ]);
                    }
                } catch (Exception $e) {
                    log_webhook_message("Warning: Failed to sync fallback refund item: " . $e->getMessage());
                }
            }
            return;
        }

        try {
            $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM reservation_refunds WHERE mercadopago_refund_id = :refund_id');
            $insStmt = $pdo->prepare('
                INSERT INTO reservation_refunds (
                    reservation_uid, mercadopago_refund_id, mercadopago_payment_id,
                    amount, status, reason, source, admin_user_id, created_at
                ) VALUES (
                    :uid, :refund_id, :payment_id, :amount, :status, :reason, :source, NULL, CURRENT_TIMESTAMP
                )
            ');

            foreach ($refundsList as $refund) {
                if (!is_array($refund)) {
                    continue;
                }
                $refundId = isset($refund['id']) ? (string) $refund['id'] : null;
                if ($refundId !== null && $refundId !== '') {
                    $checkStmt->execute(['refund_id' => $refundId]);
                    if ((int) $checkStmt->fetchColumn() > 0) {
                        continue; // Already synced
                    }
                }

                $amount = (float) ($refund['amount'] ?? $fallbackAmount);
                if ($amount <= 0) {
                    continue;
                }

                $status = (string) ($refund['status'] ?? 'approved');
                $insStmt->execute([
                    'uid' => $uid,
                    'refund_id' => $refundId,
                    'payment_id' => $paymentId !== '' ? $paymentId : 'external_webhook',
                    'amount' => $amount,
                    'status' => $status,
                    'reason' => 'Mercado Pago external refund event',
                    'source' => $source,
                ]);
            }
        } catch (Exception $e) {
            log_webhook_message("Warning: Failed to sync refund items: " . $e->getMessage());
        }
    }
}

/**
 * Records an audit log entry in admin_audit_logs if the table exists.
 *
 * @param PDO $pdo
 * @param string $action
 * @param string $uid
 * @param array<string, mixed> $payloadBefore
 * @param array<string, mixed> $payloadAfter
 */
if (!function_exists('record_webhook_audit_log')) {
    function record_webhook_audit_log(
        PDO $pdo,
        string $action,
        string $uid,
        array $payloadBefore,
        array $payloadAfter
    ): void {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO admin_audit_logs (
                    admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, created_at
                ) VALUES (
                    NULL, :action, "reservation", :entity_id, :payload_before, :payload_after, :ip_address, CURRENT_TIMESTAMP
                )
            ');
            $stmt->execute([
                'action' => $action,
                'entity_id' => $uid,
                'payload_before' => json_encode($payloadBefore, JSON_UNESCAPED_SLASHES),
                'payload_after' => json_encode($payloadAfter, JSON_UNESCAPED_SLASHES),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Exception $e) {
            // Ignore audit log error in environments without admin_audit_logs
        }
    }
}

// 4. Extract Payment ID (Support Webhooks & IPNs gracefully)
$paymentId = null;
$topic = null;

// Parse incoming raw JSON
$rawInput = file_get_contents('php://input');
$inputData = !empty($rawInput) ? json_decode((string) $rawInput, true) : null;

if (!empty($inputData) && is_array($inputData)) {
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

$paymentId = (string) $paymentId;
log_webhook_message("Processing payload for Payment ID: " . $paymentId);

// 5. Establish Database Connection
$pdo = $GLOBALS['TEST_PDO'] ?? null;
if ($pdo === null) {
    try {
        $pdo = get_db_connection($config['db']);
    } catch (PDOException $e) {
        log_webhook_message("Database offline: " . $e->getMessage());
        http_response_code(503);
        exit("Service Temporarily Unavailable.");
    }
}

// 6. Server-to-Server Verification (Bulletproof protection against spoofing)
if (isset($GLOBALS['TEST_MP_PAYMENT_DATA']) && is_array($GLOBALS['TEST_MP_PAYMENT_DATA'])) {
    $paymentData = $GLOBALS['TEST_MP_PAYMENT_DATA'];
} else {
    $mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
    if (empty($mpAccessToken)) {
        log_webhook_message("CRITICAL: MERCADOPAGO_ACCESS_TOKEN environment variable not set.");
        http_response_code(500);
        exit("Server configuration error.");
    }

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
        log_webhook_message("Verification query failed with code: " . $httpCode . " Response: " . (string)$responseStr);
        http_response_code(400);
        exit("Verification failed.");
    }

    $paymentData = json_decode((string) $responseStr, true);
    if (empty($paymentData) || !is_array($paymentData)) {
        log_webhook_message("Empty verification payload.");
        http_response_code(400);
        exit("Invalid payment data.");
    }
}

// 7. Inspect transaction status & amounts
$status = (string) ($paymentData['status'] ?? '');
$statusDetail = (string) ($paymentData['status_detail'] ?? '');
$uid = (string) ($paymentData['external_reference'] ?? ''); // This is our reservation_uid
$totalAmount = (float) ($paymentData['transaction_amount'] ?? 0.0);
$refundedAmount = (float) ($paymentData['transaction_amount_refunded'] ?? 0.0);
$refundsList = isset($paymentData['refunds']) && is_array($paymentData['refunds']) ? $paymentData['refunds'] : [];

log_webhook_message("Verified Payment ID: {$paymentId}. Status: {$status}. Detail: {$statusDetail}. Reservation: {$uid}. Refunded: {$refundedAmount}/{$totalAmount}");

if (empty($uid)) {
    log_webhook_message("Ignored: payment is not associated with an OceanViewFlats reservation reference.");
    http_response_code(200);
    echo json_encode(["success" => true, "status" => "no_reference"]);
    exit;
}

// Find matching reservation
$stmt = $pdo->prepare("SELECT * FROM `reservations` WHERE `reservation_uid` = :uid LIMIT 1");
$stmt->execute(['uid' => $uid]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    log_webhook_message("Ignored: Reference reservation {$uid} not found in database.");
    http_response_code(200);
    echo json_encode(["success" => true, "status" => "reservation_not_found"]);
    exit;
}

$resStatus = (string) ($reservation['status'] ?? '');
$resTotalPrice = (float) ($reservation['total_price'] ?? 0.0);
if ($totalAmount <= 0.0) {
    $totalAmount = $resTotalPrice;
}

// 8. Branch 1: External Full Refund / Cancellation Synchronization (Rule 2)
$isFullRefund = $status === 'refunded' || ($refundedAmount >= $totalAmount && $totalAmount > 0.0 && $refundedAmount > 0.0);

if ($isFullRefund) {
    if ($resStatus !== 'cancelled') {
        try {
            $pdo->beginTransaction();

            $actualRefundAmount = $refundedAmount > 0.0 ? $refundedAmount : $totalAmount;
            $cancelNote = '[External Refund Sync ' . date('Y-m-d H:i') . '] Full refund detected via Mercado Pago webhook (COP ' . number_format($actualRefundAmount, 2) . ')';
            $existingNotes = isset($reservation['notes']) && is_string($reservation['notes']) ? trim($reservation['notes']) : '';
            $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote;

            $upStmt = $pdo->prepare("
                UPDATE `reservations` 
                SET `status` = 'cancelled', 
                    `payment_status` = 'refunded', 
                    `refunded_amount` = :refunded_amount, 
                    `notes` = :notes,
                    `updated_at` = CURRENT_TIMESTAMP
                WHERE `reservation_uid` = :uid
            ");
            $upStmt->execute([
                'uid' => $uid,
                'refunded_amount' => $actualRefundAmount,
                'notes' => $updatedNotes,
            ]);

            sync_refund_items($pdo, $uid, $paymentId, $refundsList, $actualRefundAmount, 'mercadopago_webhook');

            record_webhook_audit_log(
                $pdo,
                'external_refund_cancellation',
                $uid,
                ['status' => $resStatus, 'payment_status' => $reservation['payment_status'] ?? null],
                ['status' => 'cancelled', 'payment_status' => 'refunded', 'refunded_amount' => $actualRefundAmount]
            );

            $pdo->commit();
            log_webhook_message("Reservation {$uid} CANCELLED via external Mercado Pago full refund webhook.");
        } catch (Exception $txEx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            log_webhook_message("External full refund sync failed: " . $txEx->getMessage());
            http_response_code(500);
            exit("Database update failed.");
        }
    } else {
        // Reservation is already cancelled (e.g. via Admin PMS). Sync refund lines if needed.
        try {
            $actualRefundAmount = $refundedAmount > 0.0 ? $refundedAmount : (float) ($reservation['refunded_amount'] ?? $totalAmount);
            $upStmt = $pdo->prepare("
                UPDATE `reservations` 
                SET `payment_status` = 'refunded', 
                    `refunded_amount` = :refunded_amount, 
                    `updated_at` = CURRENT_TIMESTAMP
                WHERE `reservation_uid` = :uid
            ");
            $upStmt->execute([
                'uid' => $uid,
                'refunded_amount' => $actualRefundAmount,
            ]);

            sync_refund_items($pdo, $uid, $paymentId, $refundsList, $actualRefundAmount, 'mercadopago_webhook');
            log_webhook_message("Reservation {$uid} was already cancelled. Synced external refund lines.");
        } catch (Exception $e) {
            log_webhook_message("Warning: Failed to update already cancelled reservation refund sync: " . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode(["success" => true, "status" => "refunded", "reservation_status" => "cancelled"]);
    exit;
}

// 9. Branch 2: External Partial Refund Synchronization
$isPartialRefund = $status === 'partially_refunded' 
    || $statusDetail === 'partially_refunded' 
    || ($refundedAmount > 0.0 && $refundedAmount < $totalAmount);

if ($isPartialRefund) {
    try {
        $pdo->beginTransaction();

        $upStmt = $pdo->prepare("
            UPDATE `reservations` 
            SET `payment_status` = 'partially_refunded', 
                `refunded_amount` = :refunded_amount, 
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `reservation_uid` = :uid
        ");
        $upStmt->execute([
            'uid' => $uid,
            'refunded_amount' => $refundedAmount,
        ]);

        sync_refund_items($pdo, $uid, $paymentId, $refundsList, $refundedAmount, 'mercadopago_webhook');

        record_webhook_audit_log(
            $pdo,
            'external_partial_refund_sync',
            $uid,
            ['payment_status' => $reservation['payment_status'] ?? null, 'refunded_amount' => $reservation['refunded_amount'] ?? 0],
            ['payment_status' => 'partially_refunded', 'refunded_amount' => $refundedAmount]
        );

        $pdo->commit();
        log_webhook_message("Reservation {$uid} recorded PARTIAL REFUND ({$refundedAmount} COP). Reservation status: {$resStatus}.");
    } catch (Exception $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_webhook_message("Partial refund sync failed: " . $txEx->getMessage());
        http_response_code(500);
        exit("Database update failed.");
    }

    http_response_code(200);
    echo json_encode(["success" => true, "status" => "partially_refunded", "reservation_status" => $resStatus]);
    exit;
}

// 10. Branch 3: Payment Approved (Initial Booking Confirmation)
if ($status === 'approved') {
    // Rule 1: Resurrection Defense (cancelled is terminal)
    if ($resStatus === 'cancelled') {
        log_webhook_message("Ignored: Reservation {$uid} is cancelled (terminal state). Refusing resurrection to confirmed.");
        http_response_code(200);
        echo json_encode(["success" => true, "status" => "ignored_cancelled_terminal"]);
        exit;
    }

    if ($resStatus === 'confirmed') {
        log_webhook_message("Ignored: Reservation {$uid} is already confirmed.");
        http_response_code(200);
        echo json_encode(["success" => true, "status" => "already_confirmed"]);
        exit;
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
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `reservation_uid` = :uid AND `status` = 'pending_payment'
        ");
        $upStmt->execute([
            'uid' => $uid,
            'pay_id' => $paymentId,
            'pay_status' => $status,
            'pay_method' => $paymentData['payment_method_id'] ?? ''
        ]);
        
        $pdo->commit();
        log_webhook_message("Reservation {$uid} successfully CONFIRMED in database.");
    } catch (Exception $txEx) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        log_webhook_message("Transaction failed: " . $txEx->getMessage());
        http_response_code(500);
        exit("Database update failed.");
    }

    // Post-settlement fulfillment via authoritative BookingFulfillment service
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
    // If status is not approved or refunded, log the status
    log_webhook_message("Payment status is '{$status}' (unhandled status). No state mutation taken.");
}

// 11. Respond to MercadoPago with standard OK code (200) to stop webhook retries
http_response_code(200);
echo json_encode(["success" => true, "status" => $status]);
