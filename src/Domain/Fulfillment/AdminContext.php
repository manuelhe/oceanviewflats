<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

/**
 * Immutable value object holding administrative operator context for audit logging.
 */
final class AdminContext
{
    public function __construct(
        public readonly ?int $adminUserId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null
    ) {}
}
