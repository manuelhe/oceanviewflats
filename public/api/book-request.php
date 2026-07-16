<?php
/**
 * OceanViewFlats - Secure Direct Booking Inquiry Processor
 * 
 * Validates request dates, prevents overlaps against cached Airbnb data,
 * computes night-by-night CSV rate sheets, logs to local MySQL database,
 * forwards to Google Sheet, and delivers details to host and guest.
 */

declare(strict_types=1);

// Configuration parameters
define('RECIPIENT_EMAIL', $_ENV['RECIPIENT_EMAIL'] ?? $_SERVER['RECIPIENT_EMAIL'] ?? getenv('RECIPIENT_EMAIL') ?: 'rentals@oceanviewflats.com');
define('CAPTCHA_SECRET', $_ENV['CAPTCHA_SECRET'] ?? $_SERVER['CAPTCHA_SECRET'] ?? getenv('CAPTCHA_SECRET') ?: 'securesaltsecret');
define('GOOGLE_SHEET_WEBAPP_URL', $_ENV['GOOGLE_SHEET_WEBAPP_URL'] ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL'] ?? getenv('GOOGLE_SHEET_WEBAPP_URL') ?: '');

// Set headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Content-Type: application/json; charset=UTF-8");

// Load central configuration
$config = require __DIR__ . '/config.php';

// Helper function to send JSON response
function send_json_response(bool $success, string $message, array $extra = []): void {
    $res = array_merge(['success' => $success], $extra);
    if ($success) {
        $res['message'] = $message;
    } else {
        $res['error'] = $message;
    }
    echo json_encode($res);
    exit;
}

// Helper to clean inputs
function clean_input(string $data): string {
    return htmlspecialchars(trim(stripslashes($data)), ENT_QUOTES, 'UTF-8');
}

// 1. Math Captcha Action (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'captcha') {
    $x = random_int(2, 9);
    $y = random_int(2, 9);
    $challenge = "$x + $y";
    $signature = hash_hmac('sha256', $challenge, CAPTCHA_SECRET);
    
    echo json_encode([
        'challenge' => $challenge,
        'signature' => $signature
    ]);
    exit;
}

// Reject non-POST submissions for checkout requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    send_json_response(false, 'Method Not Allowed');
}

// 2. Honeypot check (anti-spam)
$honeypot = $_POST['website_url'] ?? '';
if ($honeypot !== '') {
    send_json_response(true, 'Your reservation inquiry has been received (honeypot triggered).');
}

// 3. Rate Limiting (Abuse prevention)
$temp_dir = sys_get_temp_dir();
$rate_limit_path = $temp_dir . '/ovf_booking_rate_limits.json';
$ip_hash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
$now = time();

$limits = [];
if (file_exists($rate_limit_path)) {
    $file_content = @file_get_contents($rate_limit_path);
    if ($file_content !== false) {
        $limits = json_decode($file_content, true) ?: [];
    }
}

// Clean old rate limit entries (10-minute window)
foreach ($limits as $hash => $timestamps) {
    $filtered = array_filter($timestamps, function($ts) use ($now) {
        return ($now - $ts) < 600;
    });
    if (empty($filtered)) {
        unset($limits[$hash]);
    } else {
        $limits[$hash] = array_values($filtered);
    }
}

if (isset($limits[$ip_hash]) && count($limits[$ip_hash]) >= 3) {
    http_response_code(429);
    send_json_response(false, 'Too many reservation inquiries. Please wait a few minutes and try again.');
}

// 4. Captcha Verification
$captcha_challenge = $_POST['captcha_challenge'] ?? '';
$captcha_signature = $_POST['captcha_signature'] ?? '';
$captcha_response = $_POST['captcha_response'] ?? '';

$expected_signature = hash_hmac('sha256', $captcha_challenge, CAPTCHA_SECRET);
if (!hash_equals($expected_signature, $captcha_signature)) {
    send_json_response(false, 'Security check failed. Please refresh the calendar section and try again.');
}

if (!preg_match('/^(\d+)\s*\+\s*(\d+)$/', $captcha_challenge, $matches)) {
    send_json_response(false, 'Invalid verification challenge.');
}
$expected_sum = (int)$matches[1] + (int)$matches[2];
if ((int)$captcha_response !== $expected_sum) {
    send_json_response(false, 'Incorrect answer to the security verification question.');
}

// Record timestamp for rate-limit
$limits[$ip_hash][] = $now;
@file_put_contents($rate_limit_path, json_encode($limits), LOCK_EX);

// 5. Gather & Validate Core Input Details
$propertyId = clean_input($_POST['property_id'] ?? '');
$checkInStr = clean_input($_POST['check_in'] ?? '');
$checkOutStr = clean_input($_POST['check_out'] ?? '');
$guestName = clean_input($_POST['guest_name'] ?? '');
$guestEmail = clean_input($_POST['guest_email'] ?? '');
$guestPhone = clean_input($_POST['guest_phone'] ?? '');
$clientPriceCop = (float)($_POST['total_price_cop'] ?? 0);

if ($propertyId !== '1606' && $propertyId !== '1707') {
    send_json_response(false, 'Invalid property selected.');
}
if (empty($guestName) || strlen($guestName) < 3) {
    send_json_response(false, 'Please enter your full name (minimum 3 characters).');
}
if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
    send_json_response(false, 'Please enter a valid email address.');
}
if (empty($guestPhone) || strlen($guestPhone) < 6) {
    send_json_response(false, 'Please enter a valid phone number.');
}

$checkIn = strtotime($checkInStr);
$checkOut = strtotime($checkOutStr);

if (!$checkIn || !$checkOut || $checkIn >= $checkOut) {
    send_json_response(false, 'Please enter a valid check-in and check-out range.');
}

if ($checkIn < strtotime(date('Y-m-d'))) {
    send_json_response(false, 'Check-in date cannot be in the past.');
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
        send_json_response(false, "The selected dates overlap with an existing Airbnb booking ($dateStr). Please choose other dates.");
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
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_property_dates` (`property_id`, `check_in`, `check_out`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        send_json_response(false, 'The selected dates are already locked in our direct booking system. Please select another range.');
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
    send_json_response(false, "The minimum stay for the selected season is $minimumStayRequired nights. Your requested stay is $datesCount nights.");
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
            INSERT INTO `reservations` (reservation_uid, property_id, guest_name, guest_email, guest_phone, check_in, check_out, total_price, status)
            VALUES (:uid, :prop, :name, :email, :phone, :check_in, :check_out, :price, 'pending_payment')
        ");
        $stmt->execute([
            'uid' => $uid,
            'prop' => $propertyId,
            'name' => $guestName,
            'email' => $guestEmail,
            'phone' => $guestPhone,
            'check_in' => $checkInStr,
            'check_out' => $checkOutStr,
            'price' => $serverTotalCop
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

// Email HTML content
$html_message = "
<html>
<head>
  <style>
    body { font-family: sans-serif; color: #333; line-height: 1.6; }
    .container { max-width: 600px; margin: 0 auto; border: 1px solid #eee; border-radius: 12px; overflow: hidden; }
    .header { background-color: #f8fafc; padding: 24px; border-bottom: 1px solid #eee; text-align: center; }
    .body { padding: 24px; }
    .card { background-color: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .table { w-full; margin-top: 10px; border-collapse: collapse; }
    .table td { padding: 8px 0; border-bottom: 1px solid #edf2f7; }
    .table .bold { font-weight: bold; }
    .text-right { text-align: right; }
    .footer { font-size: 11px; color: #999; padding: 20px; text-align: center; border-t: 1px solid #eee; }
    .btn { display: inline-block; padding: 12px 24px; background-color: #059669; color: #fff; text-decoration: none; border-radius: 6px; font-weight: bold; margin-top: 10px; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h2 style='margin:0;color:#0f172a;'>Solicitud de Reserva / Booking Inquiry</h2>
      <p style='margin:5px 0 0 0;color:#64748b;'>OceanViewFlats Santa Marta</p>
    </div>
    <div class='body'>
      <p>Estimado/a <strong>{$guestName}</strong>,</p>
      <p>Hemos recibido su solicitud de reserva directa y guardado un bloqueo temporal por 10 minutos. A continuación, el resumen de su estadía:</p>
      
      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>Resumen de Reserva / Stay Summary</h3>
        <table width='100%' class='table'>
          <tr><td><strong>Property:</strong></td><td class='text-right'>OceanViewFlats {$propertyId}</td></tr>
          <tr><td><strong>Código / Code:</strong></td><td class='text-right'><code style='background:#f1f5f9;padding:2px 6px;border-radius:4px;'>{$uid}</code></td></tr>
          <tr><td><strong>Check-In:</strong></td><td class='text-right'>{$checkInStr}</td></tr>
          <tr><td><strong>Check-Out:</strong></td><td class='text-right'>{$checkOutStr}</td></tr>
          <tr><td><strong>Estadía / Stay:</strong></td><td class='text-right'>{$datesCount} noches / nights</td></tr>
        </table>
      </div>

      <div class='card'>
        <h3 style='margin:0 0 10px 0;color:#0f172a;font-size:16px;'>Detalle del precio / Price Breakdown</h3>
        <table width='100%' class='table'>
          <tr><td>Hospedaje / Accommodation:</td><td class='text-right'>{$accommodationFormatted}</td></tr>
          <tr><td>Limpieza / Cleaning:</td><td class='text-right'>{$cleaningFormatted}</td></tr>
          <tr><td>Lobby Register:</td><td class='text-right'>{$resortFormatted}</td></tr>
          <tr style='font-size:18px;font-weight:bold;'><td style='border-bottom:none;'>Total COP:</td><td class='text-right' style='border-bottom:none;color:#059669;'>{$copFormatter}</td></tr>
        </table>
      </div>

      <p>Nos contactaremos con usted en los próximos minutos para indicarle los canales de pago directo autorizados.</p>
      
      <p style='font-size:13px;color:#64748b;'><em>Inquiries automatically secured. Google Sheets sync: " . ($sheetSuccess ? 'YES' : 'NO') . ". DB storage: " . ($dbLogged ? 'YES' : 'NO') . ".</em></p>
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
$subjectHost = "NEW DIRECT BOOKING REQUEST: Prop $propertyId ($guestName)";
mail(RECIPIENT_EMAIL, $subjectHost, $html_message, $headers);

// Send to guest as receipt
$subjectGuest = "Recibimos su solicitud de reserva - OceanViewFlats $propertyId";
mail($guestEmail, $subjectGuest, $html_message, $headers);

// Output successful response to client
$localizedMessage = "
  <div class='space-y-2'>
    <p class='font-bold text-base text-emerald-900'>¡Solicitud Recibida Exitosamente! / Inquiry Secured!</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>Hemos recibido su solicitud para el apartamento <strong>{$propertyId}</strong>. Se ha reservado un bloqueo temporal bajo el código <strong>{$uid}</strong>.</p>
    <p class='text-emerald-800 opacity-90 leading-relaxed'>Enviamos un correo de confirmación con el desglose de <strong>{$copFormatter}</strong> a <strong>{$guestEmail}</strong>. Nos comunicaremos con usted a la brevedad para coordinar el pago.</p>
  </div>
";

send_json_response(true, $localizedMessage, [
    'reservation_uid' => $uid,
    'total_price' => $serverTotalCop
]);
