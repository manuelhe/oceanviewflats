<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

enum AlertSeverity: string
{
    case CRITICAL = 'critical'; // Check-in today or past; immediate action
    case WARNING = 'warning';   // Check-in in 24–48 hours
    case INFO = 'info';         // Check-in in 48–72 hours or un-onboarded OTA block
}
