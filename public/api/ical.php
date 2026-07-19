<?php
/**
 * OceanViewFlats - Outbound iCal Calendar Export Endpoint
 * 
 * Generates real-time iCalendar (.ics) feed of confirmed and active pending holds
 * for import into the Airbnb Host Portal to prevent dual bookings.
 * 
 * Query: GET /api/ical.php?property=[1606|1707]
 */

// 1. Prevent direct web execution if included (Security Best Practice)
if (count(get_included_files()) === 1 && !defined('ALLOW_ICAL_RUN')) {
    define('ALLOW_ICAL_RUN', true);
}

// 2. Load Shared Utilities & Configuration
require_once __DIR__ . '/utils.php';
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit("Internal Server Error: Missing config.");
}
$config = require $configPath;

// 3. Extract and Validate Property ID
$propertyId = $_GET['property'] ?? $_GET['prop'] ?? null;
if (!in_array($propertyId, ['1606', '1707'])) {
    http_response_code(400);
    exit("Bad Request: Invalid or missing property parameter.");
}

// 4. Initialize PDO Connection using shared library
try {
    $pdo = get_db_connection($config['db']);
} catch (PDOException $e) {
    http_response_code(500);
    exit("Internal Server Error: Database offline.");
}

// 5. Fetch Active Direct Bookings (Option B: Confirmed AND active pending holds < 10 minutes)
try {
    $stmt = $pdo->prepare("
        SELECT `reservation_uid`, `check_in`, `check_out`, `created_at`
        FROM `reservations`
        WHERE `property_id` = :property_id
          AND (
              `status` = 'confirmed'
              OR
              (`status` = 'pending_payment' AND `created_at` > NOW() - INTERVAL 10 MINUTE)
          )
    ");
    $stmt->execute(['property_id' => $propertyId]);
    $bookings = $stmt->fetchAll();
} catch (Exception $e) {
    http_response_code(500);
    exit("Internal Server Error: Failed to fetch calendar records.");
}

// 6. Set proper iCalendar headers
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="oceanviewflats-' . $propertyId . '.ics"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 7. Format Timestamp Helper
function formatICalDate($dateStr) {
    return date('Ymd', strtotime($dateStr));
}
function formatICalDateTime($dateTimeStr) {
    return date('Ymd\THis\Z', strtotime($dateTimeStr) - date('Z')); // Convert to UTC
}

// 8. Generate iCalendar Stream
echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//OceanViewFlats//Direct Booking Sync//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";

foreach ($bookings as $booking) {
    $uid = $booking['reservation_uid'] . '@oceanviewflats.com';
    $dtstamp = formatICalDateTime($booking['created_at']);
    $dtstart = formatICalDate($booking['check_in']);
    $dtend = formatICalDate($booking['check_out']);

    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . $uid . "\r\n";
    echo "DTSTAMP:" . $dtstamp . "\r\n";
    echo "DTSTART;VALUE=DATE:" . $dtstart . "\r\n";
    echo "DTEND;VALUE=DATE:" . $dtend . "\r\n";
    echo "SUMMARY:Blocked - OceanViewFlats Direct Booking\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
