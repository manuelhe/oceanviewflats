<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Auth;

/**
 * Contract for IP-level velocity and brute-force rate limiting.
 */
interface IpRateLimiterInterface
{
    /**
     * Checks if the given IP address is permitted to make an authentication attempt.
     */
    public function isAllowed(string $ip): bool;

    /**
     * Records a failed authentication attempt for the IP address.
     */
    public function recordFailure(string $ip): void;

    /**
     * Returns the remaining cooldown in seconds for rate-limited IP addresses.
     */
    public function getRetryAfter(string $ip): int;

    /**
     * Clears recorded attempts for the IP address (e.g. upon successful authentication).
     */
    public function reset(string $ip): void;
}
