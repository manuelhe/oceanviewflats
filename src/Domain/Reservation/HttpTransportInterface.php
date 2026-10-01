<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Transport interface for executing HTTP GET requests to external channel feeds.
 */
interface HttpTransportInterface
{
    /**
     * Executes an HTTP GET request.
     *
     * @param string $url Target URL
     * @param array<string, mixed> $options Optional settings (e.g. timeout, userAgent)
     * @return array{statusCode: int, body: string, error: ?string}
     */
    public function get(string $url, array $options = []): array;
}
