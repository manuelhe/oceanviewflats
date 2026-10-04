<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

enum MovementType: string
{
    case CHECK_IN = 'check_in';
    case CHECK_OUT = 'check_out';
    case TURNOVER = 'turnover'; // Same-day check-out & check-in for the same property
}
