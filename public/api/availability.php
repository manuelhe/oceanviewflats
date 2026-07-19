<?php
/**
 * OceanViewFlats - Availability Sync API
 * 
 * Fetches, parses, and caches public Airbnb iCal feeds for properties 1606 and 1707.
 * Prevents double bookings by disabling already-booked dates in the frontend calendar.
 */

// Load central utilities
require_once __DIR__ . '/utils.php';

// Enforce security headers & CORS policy dynamically
enforce_security_headers_and_cors(['GET', 'OPTIONS']);

// Validate property parameter
$propertyId = isset($_GET['property']) ? $_GET['property'] : '1606';
if ($propertyId !== '1606' && $propertyId !== '1707') {
    http_response_code(400);
    echo json_encode(["error" => "Invalid property ID. Must be 1606 or 1707."]);
    exit();
}

// Enforce rate-limit for availability inquiries (avoids API scraping/abuse)
enforce_rate_limit('ovf_avail_rate_limits.json', 60, 600, 'Too many requests. Please wait a few minutes and try again.');

// Load configuration (Security Best Practice: Decouple credentials and feeds from main logic controllers)
$config = require __DIR__ . '/config.php';
$icalFeeds = isset($config['ical_feeds']) ? $config['ical_feeds'] : [];

if (!isset($icalFeeds[$propertyId])) {
    http_response_code(500);
    echo json_encode(["error" => "No feed configured for property ID: " . $propertyId]);
    exit();
}

$feedUrl = $icalFeeds[$propertyId];

// Define caching directory and file
$cacheDir = __DIR__ . '/../cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = $cacheDir . '/avail_' . $propertyId . '.json';
$cacheLifetime = 15 * 60; // 15 minutes (in seconds)

// Check if valid cache exists
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {
    $cachedData = file_get_contents($cacheFile);
    if ($cachedData !== false) {
        echo $cachedData;
        exit();
    }
}

// Fetch the iCal feed
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $feedUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3');
$icalData = curl_exec($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Fallback logic if feed fetch fails
if ($httpStatusCode !== 200 || !$icalData) {
    // If we have an expired cache file, use it as fallback rather than failing
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
        exit();
    }
    http_response_code(502);
    echo json_encode(["error" => "Failed to retrieve calendar feed from Airbnb.", "status_code" => $httpStatusCode]);
    exit();
}

// Parse the iCal VEVENT blocks to extract start/end dates
$blockedDates = [];
$lines = explode("\n", str_replace("\r", "", $icalData));
$isEvent = false;
$dtStart = '';
$dtEnd = '';

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === 'BEGIN:VEVENT') {
        $isEvent = true;
        $dtStart = '';
        $dtEnd = '';
    } elseif ($line === 'END:VEVENT') {
        if ($isEvent && !empty($dtStart) && !empty($dtEnd)) {
            // Calculate nights to block (inclusive start, exclusive end)
            $start = strtotime($dtStart);
            $end = strtotime($dtEnd);
            
            if ($start && $end && $start < $end) {
                $current = $start;
                while ($current < $end) {
                    $blockedDates[] = date('Y-m-d', $current);
                    $current = strtotime("+1 day", $current);
                }
            }
        }
        $isEvent = false;
    } elseif ($isEvent) {
        if (strpos($line, 'DTSTART') === 0) {
            $parts = explode(':', $line);
            $dateVal = end($parts);
            $dtStart = substr($dateVal, 0, 8); // Format YYYYMMDD
        } elseif (strpos($line, 'DTEND') === 0) {
            $parts = explode(':', $line);
            $dateVal = end($parts);
            $dtEnd = substr($dateVal, 0, 8); // Format YYYYMMDD
        }
    }
}

// Ensure unique, sorted dates
$blockedDates = array_values(array_unique($blockedDates));
sort($blockedDates);

// Save parsed array to local cache
file_put_contents($cacheFile, json_encode($blockedDates));

// Output JSON
echo json_encode($blockedDates);
