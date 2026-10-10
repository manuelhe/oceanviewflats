<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Port;

use OceanViewFlats\Domain\Reservation\ActorContext;

/**
 * Hexagonal port for immutable administrative audit logging.
 */
interface AuditPort
{
    /**
     * Records an administrative audit log entry.
     *
     * @param string $action
     * @param string $entityType
     * @param string $entityId
     * @param array<string, mixed>|null $payloadBefore
     * @param array<string, mixed>|null $payloadAfter
     * @param ActorContext|null $actor
     * @return int|null Inserted log ID if available
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?ActorContext $actor = null
    ): ?int;
}
