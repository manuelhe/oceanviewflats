<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Value object capturing the actor context initiating a reservation lifecycle transition.
 */
final class ActorContext
{
    public function __construct(
        public readonly ?int $adminUserId = null,
        public readonly string $ipAddress = '',
        public readonly ?string $userAgent = null,
        public readonly string $source = 'system'
    ) {
    }

    public static function system(string $ipAddress = '', ?string $userAgent = null): self
    {
        return new self(
            adminUserId: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            source: 'system'
        );
    }

    public static function admin(int $adminUserId, string $ipAddress = '', ?string $userAgent = null): self
    {
        return new self(
            adminUserId: $adminUserId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            source: 'admin'
        );
    }

    public static function guest(string $ipAddress = '', ?string $userAgent = null): self
    {
        return new self(
            adminUserId: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            source: 'guest'
        );
    }

    public static function webhook(string $ipAddress = '', ?string $userAgent = null): self
    {
        return new self(
            adminUserId: null,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            source: 'webhook'
        );
    }

    public function isAdmin(): bool
    {
        return $this->adminUserId !== null || $this->source === 'admin';
    }
}
