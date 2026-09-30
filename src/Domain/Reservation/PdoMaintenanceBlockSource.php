<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * PDO database-backed adapter for administrative maintenance blocks per ADR 0006.
 *
 * Backward-compatibility wrapper extending PdoMaintenanceBlockRepository.
 */
class PdoMaintenanceBlockSource extends PdoMaintenanceBlockRepository
{
}
