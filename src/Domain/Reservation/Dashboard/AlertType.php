<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

enum AlertType: string
{
    case INCOMPLETE_GUEST_REGISTRY = 'incomplete_guest_registry';
    case UNONBOARDED_CHANNEL_BLOCK = 'unonboarded_channel_block';
}
