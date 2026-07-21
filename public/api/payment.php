<?php
/**
 * OceanViewFlats - Secure Custom API Booking & MercadoPago Checkout Bricks Processor
 * 
 * Validates request dates, checks overlap, computes CSV nightly rates,
 * processes payment directly via server-to-server MercadoPago payments API,
 * logs to MySQL database, syncs with Google Sheets, and delivers localized emails.
 */

declare(strict_types=1);

// Load central utilities & configuration
require_once __DIR__ . '/utils.php';
$config = require __DIR__ . '/config.php';

// Configuration parameters
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'rentals@oceanviewflats.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');
define('GOOGLE_SHEET_WEBAPP_URL', $_ENV['GOOGLE_SHEET_WEBAPP_URL'] ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL'] ?? getenv('GOOGLE_SHEET_WEBAPP_URL') ?: '');

// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['POST', 'OPTIONS']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// Read and decode secure JSON raw inputs
$input_json = file_get_contents('php://input');
$data = json_decode($input_json, true) ?: [];

// Extract language
$lang = get_validated_lang($data['lang'] ?? '');

$all_translations = require __DIR__ . '/translations.php';
$t = $all_translations[$lang]['booking'] ?? $all_translations['en']['booking'];
$t_web = $all_translations[$lang]['webhook'] ?? $all_translations['en']['webhook'];

// Honeypot check
if (!empty($data['website_url'])) {
    send_json_response(true, 'Booking request received.');
}

// Rate Limits
const RATE_LIMIT_FILE = 'ovf_payments_rate_limits.json';
const MAX_SUBMISSIONS = 5;
const RATE_LIMIT_WINDOW = 600; // 10 minutes
enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, $t['err_rate_limit']);

// Captcha Verification
$captcha_challenge = clean_input($data['captcha_challenge'] ?? '');
$captcha_signature = clean_input($data['captcha_signature'] ?? '');
$captcha_response = clean_input($data['captcha_response'] ?? '');

$captcha_check = verify_captcha_challenge(
    $captcha_challenge, 
    $captcha_signature, 
    $captcha_response, 
    CAPTCHA_SECRET,
    $t['err_captcha_sign'],
    $t['err_captcha_invalid'],
    $t['err_captcha_wrong']
);
if ($captcha_check !== true) {
    send_json_response(false, $captcha_check);
}

// Gather Core Inputs
$propertyId = clean_input($data['property_id'] ?? '');
$checkInStr = clean_input($data['check_in'] ?? '');
$checkOutStr = clean_input($data['check_out'] ?? '');
$guestName = clean_input($data['guest_name'] ?? '');
$guestEmail = clean_input($data['guest_email'] ?? '');
$guestPhone = clean_input($data['guest_phone'] ?? '');

if ($propertyId !== '1606' && $propertyId !== '1707') {
    send_json_response(false, $t['err_property']);
}
if (empty($guestName) || strlen($guestName) < 3) {
    send_json_response(false, $t['err_name']);
}
if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    send_json_response(false, $t['err_email']);
}
if (empty($guestPhone) || strlen($guestPhone) < 6) {
    send_json_response(false, $t['err_phone']);
}

$checkIn = strtotime($checkInStr);
$checkOut = strtotime($checkOutStr);

if (!$checkIn || !$checkOut || $checkIn >= $checkOut) {
    send_json_response(false, $t['err_dates_invalid']);
}
if ($checkIn < strtotime(date('Y-m-d'))) {
    send_json_response(false, $t['err_dates_past']);
}

// Overlap check: Airbnb Cache iCal
$cacheFile = __DIR__ . '/../cache/avail_' . $propertyId . '.json';
$blockedDates = [];
if (file_exists($cacheFile)) {
    $cacheContent = @file_get_contents($cacheFile);
    if ($cacheContent !== false) {
        $blockedDates = json_decode($cacheContent, true) ?: [];
    }
}

$requestedNights = [];
$curr = $checkIn;
while ($curr < $checkOut) {
    $dateStr = date('Y-m-d', $curr);
    $requestedNights[] = $dateStr;
    if (in_array($dateStr, $blockedDates, true)) {
        send_json_response(false, sprintf($t['err_overlap_airbnb'], $dateStr));
    }
    $curr = strtotime("+1 day", $curr);
}

// Establishing DB Connection
$pdo = null;
try {
    $dsn = "mysql:host=" . $config['db']['host'] . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $dbname = $config['db']['dbname'];
    $pdo->exec("USE `$dbname`");

    // Double-Booking overlapping reservations check
    $stmt = $pdo->prepare("
        SELECT id FROM `reservations`
        WHERE `property_id` = :prop_id
          AND `status` != 'cancelled'
          AND (
            (`check_in` <= :check_in AND `check_out` > :check_in) OR
            (`check_in` < :check_out AND `check_out` >= :check_out) OR
            (:check_in <= `check_in` AND :check_out >= `check_out`)
          )
    ");
    $stmt->execute([
        'prop_id' => $propertyId,
        'check_in' => $checkInStr,
        'check_out' => $checkOutStr
    ]);
    if ($stmt->fetch()) {
        send_json_response(false, $t['err_overlap_db']);
    }
} catch (PDOException $e) {
    error_log("Database execution/migration failed in payment.php: " . $e->getMessage());
}

// Pricing Calculation via prices.csv
$csvPath = __DIR__ . '/../data/prices.csv';
$pricesData = [];
if (file_exists($csvPath)) {
    $csvFile = fopen($csvPath, 'r');
    if ($csvFile !== false) {
        fgetcsv($csvFile); // Skip header
        while (($row = fgetcsv($csvFile)) !== false) {
            if (count($row) >= 5) {
                $pricesData[] = [
                    'property_id' => $row[0],
                    'start_date' => $row[1],
                    'end_date' => $row[2],
                    'nightly_rate_cop' => (float)$row[3],
                    'minimum_stay' => (int)$row[4]
                ];
            }
        }
        fclose($csvFile);
    }
}

$accommodationTotal = 0.0;
$minimumStayRequired = 2;
$datesCount = count($requestedNights);

foreach ($requestedNights as $night) {
    $tierFound = null;
    foreach ($pricesData as $tier) {
        if ($tier['property_id'] === $propertyId && $night >= $tier['start_date'] && $night <= $tier['end_date']) {
            $tierFound = $tier;
            break;
        }
    }
    $rate = $tierFound ? $tierFound['nightly_rate_cop'] : ($propertyId === '1707' ? 450000.0 : 350000.0);
    if ($tierFound) {
        $minimumStayRequired = max($minimumStayRequired, $tierFound['minimum_stay']);
    }
    $accommodationTotal += $rate;
}

if ($datesCount < $minimumStayRequired) {
    send_json_response(false, sprintf($t['err_min_stay'], $minimumStayRequired, $datesCount));
}

$cleaningFee = $propertyId === '1707' ? 100000.0 : 80000.0;
$resortFee = 20000.0;
$serverTotalCop = $accommodationTotal + $cleaningFee + $resortFee;

// Setup Unique Reservation Code
$uid = 'ovf_' . bin2hex(random_bytes(4));

// IDEMPOTENCY DOUBLE-CLICK SHIELD CHECK
if ($pdo !== null) {
    try {
        $idemStmt = $pdo->prepare("SELECT payment_id FROM `payment_idempotency` WHERE `idempotency_key` = :key");
        $idemStmt->execute(['key' => $uid]);
        if ($idemRes = $idemStmt->fetch()) {
            send_json_response(true, 'Payment already processed successfully', [
                'success' => true,
                'payment_id' => $idemRes['payment_id'],
                'reservation_code' => $uid,
                'status' => 'approved'
            ]);
        }
    } catch (PDOException $e) {
        error_log("Idempotency lookup failed: " . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// MERCADOPAGO CUSTOM / TRANSPARENT PAYMENT INTEGRATION
// -----------------------------------------------------------------------------
$mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
if (empty($mpAccessToken)) {
    send_json_response(false, "Merchant authorization is misconfigured. Please contact support.");
}

$paymentData = [
    "transaction_amount" => (float) $serverTotalCop,
    "description" => "Reserva OceanViewFlats Apto " . $propertyId,
    "payment_method_id" => $data['payment_method_id'] ?? '',
    "external_reference" => $uid,
    "payer" => [
        "email" => $data['payer']['email'] ?? $guestEmail,
    ]
];

// Map Identification credentials if present
if (isset($data['payer']['identification'])) {
    $paymentData['payer']['identification'] = [
        "type" => $data['payer']['identification']['type'] ?? "CC",
        "number" => $data['payer']['identification']['number'] ?? ""
    ];
}

// Handle credit/debit card tokenization
if (isset($data['token'])) {
    $paymentData['token'] = $data['token'];
    $paymentData['installments'] = (int)($data['installments'] ?? 1);
}

// Handle PSE integration
if (($data['payment_method_id'] ?? '') === 'pse') {
    $paymentData['transaction_details'] = [
        "financial_institution" => $data['transaction_details']['financial_institution'] ?? ""
    ];
    $paymentData['additional_info'] = [
        "ip_address" => $_SERVER['REMOTE_ADDR'] ?? "127.0.0.1"
    ];
    if (isset($data['payer']['entity_type'])) {
        $paymentData['payer']['entity_type'] = $data['payer']['entity_type'];
    }
}

// Call MercadoPago Custom Payments Endpoint
$ch = curl_init("https://api.mercadopago.com/v1/payments");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $mpAccessToken,
    "Content-Type: application/json",
    "X-Idempotency-Key: " . $uid
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$responseStr = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$mpResponse = json_decode($responseStr, true) ?: [];

if ($httpCode !== 200 && $httpCode !== 201) {
    $errorMsg = $mpResponse['message'] ?? ($mpResponse['cause'][0]['description'] ?? 'MercadoPago API connection failed');
    error_log("MercadoPago API Custom Payment failed: code: $httpCode, response: $responseStr");
    send_json_response(false, "Direct payment declined: " . $errorMsg);
}

$paymentId = (string)($mpResponse['id'] ?? '');
$paymentStatus = $mpResponse['status'] ?? 'pending'; // approved, pending, rejected, in_process
$paymentMethodId = $mpResponse['payment_method_id'] ?? '';
$reservationStatus = $paymentStatus === 'approved' ? 'confirmed' : 'pending_payment';

// Save the main booking reservation directly to SQLite/MySQL
if ($pdo !== null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `reservations` (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, lang, mercadopago_payment_id, payment_status, payment_method_id)
            VALUES (:uid, :prop, :name, :email, :phone, :check_in, :check_out, :price, :status, :lang, :pay_id, :pay_status, :pay_method)
        ");
        $stmt->execute([
            'uid' => $uid,
            'prop' => $propertyId,
            'name' => $guestName,
            'email' => $guestEmail,
            'phone' => $guestPhone,
            'check_in' => $checkInStr,
            'check_out' => $checkOutStr,
            'price' => $serverTotalCop,
            'status' => $reservationStatus,
            'lang' => $lang,
            'pay_id' => $paymentId,
            'pay_status' => $paymentStatus,
            'pay_method' => $paymentMethodId
        ]);

        // Insert idempotency shield logs
        $idemStmt = $pdo->prepare("INSERT INTO `payment_idempotency` (idempotency_key, payment_id) VALUES (:key, :pay_id)");
        $idemStmt->execute(['key' => $uid, 'pay_id' => $paymentId]);
    } catch (PDOException $e) {
        error_log("DB Booking insertion failed in payment.php: " . $e->getMessage());
    }
}

// Dispatch to Google Sheet Logger WebApp
$webhook_url = GOOGLE_SHEET_WEBAPP_URL;
if (!empty($webhook_url) && filter_var($webhook_url, FILTER_VALIDATE_URL)) {
    $sheetPayload = [
        'timestamp' => date('Y-m-d H:i:s'),
        'reservation_uid' => $uid,
        'property' => $propertyId,
        'check_in' => $checkInStr,
        'check_out' => $checkOutStr,
        'guest_name' => $guestName,
        'guest_email' => $guestEmail,
        'guest_phone' => $guestPhone,
        'total_price' => $serverTotalCop,
        'status' => $reservationStatus,
        'payment_id' => $paymentId,
        'payment_status' => $paymentStatus
    ];
    $sh = curl_init($webhook_url);
    curl_setopt($sh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($sh, CURLOPT_POST, true);
    curl_setopt($sh, CURLOPT_POSTFIELDS, json_encode($sheetPayload));
    curl_setopt($sh, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($sh, CURLOPT_TIMEOUT, 8);
    curl_exec($sh);
    curl_close($sh);
}

// Formatting total text currency
$copFormatter = '$' . number_get_formatted_amount($serverTotalCop) . ' COP';

// Prepare Dynamic HTML Emails
$html_message = "
<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; }
    .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .header { background-color: #0f172a; padding: 32px; text-align: center; }
    .header h1 { color: #ffffff; font-size: 24px; margin: 0; font-weight: 800; letter-spacing: -0.5px; }
    .content { padding: 32px; }
    .summary-card { background-color: #f1f5f9; padding: 24px; border-radius: 12px; margin-bottom: 24px; }
    .summary-title { font-weight: 700; font-size: 14px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 16px; }
    .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 15px; }
    .item-row:last-child { border-bottom: none; }
    .total-row { display: flex; justify-content: space-between; padding-top: 16px; margin-top: 16px; border-top: 2px solid #cbd5e1; font-size: 18px; font-weight: 800; }
    .footer { text-align: center; padding: 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
    .btn { display: inline-block; padding: 14px 28px; background-color: #10b981; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 700; margin-top: 20px; box-shadow: 0 4px 6px -1px rgba(16,185,129,0.2); }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h1>" . ($reservationStatus === 'confirmed' ? $t_web['title'] : $t['email_title']) . "</h1>
    </div>
    <div class='content'>
      <p>" . sprintf($reservationStatus === 'confirmed' ? $t_web['intro'] : $t['email_intro'], $guestName) . "</p>
      <p>" . ($reservationStatus === 'confirmed' ? $t_web['desc'] : $t['email_received']) . "</p>
      
      <div class='summary-card'>
        <div class='summary-title'>{$t['email_summary']}</div>
        <div class='item-row'><span>{$t['email_property']}</span><strong>Apto {$propertyId}</strong></div>
        <div class='item-row'><span>{$t['email_code']}</span><strong>{$uid}</strong></div>
        <div class='item-row'><span>{$t_web['nights']}</span><strong>" . sprintf($t['email_nights'], $datesCount) . " ({$checkInStr} / {$checkOutStr})</strong></div>
        <div class='item-row'><span>Guest Phone</span><strong>{$guestPhone}</strong></div>
        <div class='total-row'><span>{$t_web['total']}</span><strong>{$copFormatter}</strong></div>
      </div>";

if ($reservationStatus === 'confirmed') {
    $guideUrl = "https://www.oceanviewflats.com/guide/?code={$uid}&property={$propertyId}&lang={$lang}";
    $html_message .= "<p class='footer-note'>{$t_web['footer']}</p>";
    $html_message .= "<div style='text-align: center;'><a href='{$guideUrl}' class='btn'>{$t_web['btn_guide']}</a></div>";
} else {
    $html_message .= "<p class='footer-note'>{$t['email_footer']}</p>";
}

$html_message .= "
    </div>
    <div class='footer'>
      &copy; " . date('Y') . " OceanViewFlats. All rights reserved.
    </div>
  </div>
</body>
</html>
";

$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: OceanViewFlats <no-reply@oceanviewflats.com>\r\n";
$headers .= "Reply-To: rentals@oceanviewflats.com\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Email deliveries
$subjectHost = ($reservationStatus === 'confirmed' ? "BOOKING PAID & CONFIRMED: " : "BOOKING INQUIRY: ") . "Prop $propertyId ($guestName) - [" . strtoupper($lang) . "]";
mail(RECIPIENT_EMAIL, $subjectHost, $html_message, $headers);

$subjectGuest = sprintf($reservationStatus === 'confirmed' ? $t_web['subject'] : $t['email_subject_guest'], $propertyId);
mail($guestEmail, $subjectGuest, $html_message, $headers);

// Build response details based on the payment method category
$responseDetails = [
    'reservation_code' => $uid,
    'payment_id' => $paymentId,
    'status' => $paymentStatus,
];

// Add external PSE redirection URL if pending PSE bank authorization
if ($paymentMethodId === 'pse' && isset($mpResponse['transaction_details']['external_resource_url'])) {
    $responseDetails['external_resource_url'] = $mpResponse['transaction_details']['external_resource_url'];
}

// Add ticket details if paying with cash/vouchers
if ($paymentMethodId === 'efecty') {
    $responseDetails['barcode'] = $mpResponse['transaction_details']['barcode']['content'] ?? '';
    $responseDetails['verification_code'] = $mpResponse['transaction_details']['verification_code'] ?? '';
    $responseDetails['printable_voucher_url'] = $mpResponse['transaction_details']['external_resource_url'] ?? '';
}

send_json_response(true, 'Payment request processed successfully', $responseDetails);
