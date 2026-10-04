<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Dashboard;

use DateTimeImmutable;

interface DashboardQueryServiceInterface
{
    /**
     * Aggregates all operational metrics, 7-day schedule, alerts, rate statuses, and blocks
     * into a single unified view model for the administrative dashboard hub.
     *
     * @param string $propertyId 'all' | '1606' | '1707'
     */
    public function getDashboardHubData(
        string $propertyId = 'all',
        ?DateTimeImmutable $now = null
    ): DashboardHubViewData;
}
