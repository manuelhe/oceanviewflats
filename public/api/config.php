<?php
/**
 * OceanViewFlats - API Configuration
 * 
 * Contains secure credentials, API settings, and private Airbnb sync iCal feed URLs.
 */

// Prevent direct web access to config.php (Security Best Practice)
if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit("Direct access forbidden.");
}

return [
    'ical_feeds' => [
        '1606' => 'https://www.airbnb.com/calendar/ical/1584825560087571592.ics?t=55e0ddced658497c89743275f7c3a9c9',
        '1707' => 'https://www.airbnb.com/calendar/ical/1500108514798091235.ics?t=0dc27b409a1a4e64be6445f5dc2efd39'
    ]
];
