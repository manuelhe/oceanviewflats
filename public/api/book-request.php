<?php
/**
 * OceanViewFlats - Secure Direct Booking Inquiry Processor
 * 
 * Validates request dates, prevents overlaps against cached Airbnb data,
 * computes night-by-night CSV rate sheets, logs to local MySQL database,
 * forwards to Google Sheet, and delivers details to host and guest.
 * Supports complete multi-language localization (EN, ES, FR, IT, DE, JA).
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
enforce_security_headers_and_cors(['GET', 'POST', 'OPTIONS']);

// 1. Math Captcha Action (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'captcha') {
    $captcha = generate_captcha_challenge(CAPTCHA_SECRET);
    echo json_encode($captcha);
    exit;
}

// Reject non-POST submissions for checkout requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// Enforce referer check for actual submissions (prevent direct script browsing)
enforce_referer_check();

// 2. Honeypot check (anti-spam)
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    send_json_response(true, 'Your reservation inquiry has been received (honeypot triggered).');
}

// 3. Multi-language Localization Dictionary Setup
$lang = clean_input($_POST['lang'] ?? 'en');
if (!in_array($lang, ['en', 'es', 'fr', 'it', 'de', 'ja'], true)) {
    $lang = 'en';
}

$all_translations = require __DIR__ . '/translations.php';
$t = $all_translations[$lang]['booking'] ?? $all_translations['en']['booking'];

// 4. Captcha Verification
$captcha_challenge = $_POST['captcha_challenge'] ?? '';
$captcha_signature = $_POST['captcha_signature'] ?? '';
$captcha_response = $_POST['captcha_response'] ?? '';

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

const RATE_LIMIT_FILE = 'ovf_booking_rate_limits.json';
const MAX_SUBMISSIONS = 3;
const RATE_LIMIT_WINDOW = 600; // 10 minutes (600 seconds)

// Record timestamp and enforce rate-limit
enforce_rate_limit(RATE_LIMIT_FILE, MAX_SUBMISSIONS, RATE_LIMIT_WINDOW, $t['err_rate_limit']);

// 5. Gather & Validate Core Input Details
$propertyId = clean_input($_POST['property_id'] ?? '');
$checkInStr = clean_input($_POST['check_in'] ?? '');
$checkOutStr = clean_input($_POST['check_out'] ?? '');
$guestName = clean_input($_POST['guest_name'] ?? '');
$guestEmail = clean_input($_POST['guest_email'] ?? '');
$guestPhone = clean_input($_POST['guest_phone'] ?? '');
$clientPriceCop = (float)($_POST['total_price_cop'] ?? 0);

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

// 6. Overlap Booking Check Against Cache and Database
// A: Check Airbnb iCal Cache
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

// B: Establish Database Connection and check local table
$pdo = null;
try {
    $dsn = "mysql:host=" . $config['db']['host'] . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Create database and tables automatically if missing
    $dbname = $config['db']['dbname'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `reservations` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `reservation_uid` VARCHAR(36) NOT NULL UNIQUE,
      `property_id` VARCHAR(10) NOT NULL,
      `guest_name` VARCHAR(120) NOT NULL,
      `guest_email` VARCHAR(100) NOT NULL,
      `guest_phone` VARCHAR(25) NOT NULL,
      `check_in` DATE NOT NULL,
      `check_out` DATE NOT NULL,
      `total_price` DECIMAL(10, 2) NOT NULL,
      `mercadopago_preference_id` VARCHAR(255) DEFAULT NULL,
      `status` ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending_payment',
      `lang` VARCHAR(5) NOT NULL DEFAULT 'en',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Self-healing migration to add lang column if table existed beforehand
    try {
        $pdo->exec("ALTER TABLE `reservations` ADD COLUMN `lang` VARCHAR(5) NOT NULL DEFAULT 'en'");
    } catch (PDOException $e) {
        // Column already exists, safe to ignore
    }

    // Query for overlapping local reservations that are NOT cancelled
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
    // If the database connection fails, log locally but proceed with CSV calculations and email routing (high reliability)
    error_log("Direct booking MySQL connection failed: " . $e->getMessage());
}

// 7. Night-by-Night Pricing Resolution via prices.csv
$csvPath = __DIR__ . '/../data/prices.csv';
$pricesData = [];
if (file_exists($csvPath)) {
    $csvFile = fopen($csvPath, 'r');
    if ($csvFile !== false) {
        $headers = fgetcsv($csvFile);
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

// Compute total nightly rate
$accommodationTotal = 0.0;
$minimumStayRequired = 2; // Default minimum
$datesCount = count($requestedNights);

foreach ($requestedNights as $night) {
    // Find matching tier
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

// Security verification: compare computed total against client total
if (abs($serverTotalCop - $clientPriceCop) > 1.0) {
    // Audit mismatch: log and enforce server resolution
    error_log("Direct booking pricing audit mismatch: Client: $clientPriceCop, Server: $serverTotalCop.");
}

// 8. Log Booking Request to MySQL
$uid = 'ovf_' . bin2hex(random_bytes(4)); // Safe unique reservation code
$dbLogged = false;

if ($pdo !== null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO `reservations` (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status, lang)
            VALUES (:uid, :prop, :name, :email, :phone, :check_in, :check_out, :price, 'pending_payment', :lang)
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
            'lang' => $lang
        ]);
        $dbLogged = true;
    } catch (PDOException $e) {
        error_log("Database insertion failed: " . $e->getMessage());
    }
}

// 9. Forward Details to Google Sheet webhook
$sheetSuccess = false;
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
        'status' => 'pending_payment'
    ];

    $ch = curl_init($webhook_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sheetPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: OceanViewFlats Direct Booking PHP'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200) {
        $sheetSuccess = true;
    }
}

// 10. Send Structured Emails (Host & Guest)
$copFormatter = "$ " . number_format($serverTotalCop, 0, ',', '.') . " COP";
$accommodationFormatted = "$ " . number_format($accommodationTotal, 0, ',', '.') . " COP";
$cleaningFormatted = "$ " . number_format($cleaningFee, 0, ',', '.') . " COP";
$resortFormatted = "$ " . number_format($resortFee, 0, ',', '.') . " COP";

$stayNightsLabel = sprintf($t['email_nights'], $datesCount);

// Email HTML content - fully localized for the guest!
$html_message = "
<html>
<head>
  <style>
    body { font-family: sans-serif; color: #333; line-height: 1.6; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
    .header { background-color: #f8fafc; padding: 24px; border-bottom: 1px solid #eee; text-align: center; }
    .body { padding: 24px; }
    .card { background-color: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .table { width: 100%; margin-top: 10px; border-collapse: collapse; }
    .table td { padding: 8px 0; border-bottom: 1px solid #edf2f7; }
    .table .bold { font-weight: bold; }
    .text-right { text-align: right; }
    .footer { font-size: 11px; color: #999; padding: 20px; text-align: center; border-top: 1px solid #eee; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h2 style='margin:0;color:#0f172a;'>{$t['email_title']}</h2>
      <p style='margin:5px 0 0 0;color:#64748b;'>OceanViewFlats Santa Marta</p>
    </div>
    <div class='body'>
      <p>" . sprintf($t['email_intro'], $guestName) . "</p>
      <p>{$t['email_received']}</p>
      
      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>{$t['email_summary']}</h3>
        <table width='100%' class='table'>
          <tr><td><strong>{$t['email_property']}:</strong></td><td class='text-right'>OceanViewFlats {$propertyId}</td></tr>
          <tr><td><strong>{$t['email_code']}:</strong></td><td class='text-right'><code style='background:#f1f5f9;padding:2px 6px;border-radius:4px;'>{$uid}</code></td></tr>
          <tr><td><strong>Check-In:</strong></td><td class='text-right'>{$checkInStr}</td></tr>
          <tr><td><strong>Check-Out:</strong></td><td class='text-right'>{$checkOutStr}</td></tr>
          <tr><td><strong>Estadía / Stay:</strong></td><td class='text-right'>{$stayNightsLabel}</td></tr>
        </table>
      </div>

      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>{$t['email_breakdown']}</h3>
        <table width='100%' class='table'>
          <tr><td>{$t['email_accommodation']}:</td><td class='text-right'>{$accommodationFormatted}</td></tr>
          <tr><td>{$t['email_cleaning']}:</td><td class='text-right'>{$cleaningFormatted}</td></tr>
          <tr><td>{$t['email_resort']}:</td><td class='text-right'>{$resortFormatted}</td></tr>
          <tr style='font-size:18px;font-weight:bold;'><td style='border-bottom:none;'>{$t['email_total']}:</td><td class='text-right' style='border-bottom:none;color:#059669;'>{$copFormatter}</td></tr>
        </table>
      </div>

      <p>{$t['email_footer']}</p>
      
      <p style='font-size:13px;color:#64748b;'><em>Inquiries automatically secured. Google Sheets sync: " . ($sheetSuccess ? 'YES' : 'NO') . ". DB storage: " . ($dbLogged ? 'YES' : 'NO') . ". Language Code: " . strtoupper($lang) . ".</em></p>
    </div>
    <div class='footer'>
      &copy; 2026 OceanViewFlats. Calle 26 # 2-80, Playa Salguero, Santa Marta, Colombia.
    </div>
  </div>
</body>
</html>
";

// Secure headers for multipart HTML delivery
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: OceanViewFlats <no-reply@oceanviewflats.com>\r\n";
$headers .= "Reply-To: rentals@oceanviewflats.com\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Send to host
$subjectHost = "NEW DIRECT BOOKING REQUEST: Prop $propertyId ($guestName) - [" . strtoupper($lang) . "]";
mail(RECIPIENT_EMAIL, $subjectHost, $html_message, $headers);

// Send to guest as receipt (fully localized!)
$subjectGuest = sprintf($t['email_subject_guest'], $propertyId);
mail($guestEmail, $subjectGuest, $html_message, $headers);

// 11. Generate MercadoPago Payment Preference Session (Checkout Pro)
$mpAccessToken = $_ENV['MERCADOPAGO_ACCESS_TOKEN'] ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN'] ?? getenv('MERCADOPAGO_ACCESS_TOKEN') ?: '';
$mpSandbox = $_ENV['MERCADOPAGO_SANDBOX'] ?? $_SERVER['MERCADOPAGO_SANDBOX'] ?? getenv('MERCADOPAGO_SANDBOX') ?: 'false';
$isSandbox = (strtolower($mpSandbox) === 'true' || $mpSandbox === '1' || $mpSandbox === 1);

$redirectUrl = '';
$preferenceId = '';

if (!empty($mpAccessToken)) {
    // Compile exact localized receipt return URLs
    $baseUrl = 'https://www.oceanviewflats.com';
    $backSuccess = $lang === 'en' ? "{$baseUrl}/booking-success/" : "{$baseUrl}/booking-success/{$lang}.html";
    $backFailure = $lang === 'en' ? "{$baseUrl}/booking-failure/" : "{$baseUrl}/booking-failure/{$lang}.html";
    $backPending = $lang === 'en' ? "{$baseUrl}/booking-pending/" : "{$baseUrl}/booking-pending/{$lang}.html";

    // Call MercadoPago Preferences REST API
    $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
    $preferenceData = [
        "items" => [[
            "id" => "ovf_" . $propertyId,
            "title" => "Reserva Apto " . $propertyId . " - OceanViewFlats",
            "quantity" => 1,
            "currency_id" => "COP",
            "unit_price" => (float)$serverTotalCop
        ]],
        "payer" => [
            "name" => $guestName,
            "email" => $guestEmail,
            "phone" => ["number" => $guestPhone]
        ],
        "back_urls" => [
            "success" => $backSuccess,
            "failure" => $backFailure,
            "pending" => $backPending
        ],
        "auto_return" => "all",
        "external_reference" => $uid,
        "expires" => true,
        "date_of_expiration" => date('c', strtotime('+10 minutes'))
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($preferenceData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $mpAccessToken,
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $responseStr = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        $prefResponse = json_decode($responseStr, true);
        if (isset($prefResponse['id'])) {
            $preferenceId = $prefResponse['id'];
            $redirectUrl = $isSandbox ? $prefResponse['sandbox_init_point'] : $prefResponse['init_point'];
            
            // Link preference id inside MySQL database row
            if ($pdo !== null && $dbLogged) {
                try {
                    $upStmt = $pdo->prepare("UPDATE `reservations` SET `mercadopago_preference_id` = :pref WHERE `reservation_uid` = :uid");
                    $upStmt->execute(['pref' => $preferenceId, 'uid' => $uid]);
                } catch (PDOException $e) {
                    error_log("Failed to update reservation with preference ID: " . $e->getMessage());
                }
            }
        }
    } else {
        error_log("MercadoPago preference API error. Code: $httpCode. Response: $responseStr");
    }
}

// Output successful response to client - fully localized!
$localizedMessage = "
  <div class='space-y-2'>
    <p class='font-bold text-base text-emerald-900'>{$t['msg_success_title']}</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>" . sprintf($t['msg_success_desc1'], $propertyId, $uid) . "</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>" . sprintf($t['msg_success_desc2'], $copFormatter, $guestEmail) . "</p>
  </div>
";

send_json_response(true, $localizedMessage, [
    'reservation_uid' => $uid,
    'total_price' => $serverTotalCop,
    'redirect_url' => $redirectUrl
]);
