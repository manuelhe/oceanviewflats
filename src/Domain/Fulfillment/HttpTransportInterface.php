<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

/**
 * HTTP client transport interface for outbound fulfillment integrations.
 */
interface HttpTransportInterface
{
    /**
     * Executes an HTTP POST request.
     *
     * @param string $url Target endpoint URL
     * @param array<string, mixed>|string $data Form fields or raw payload
     * @param array<int|string, string> $headers Additional request headers
     * @param array<string, mixed> $options Transport options (e.g. 'cookies', 'timeout', 'followRedirects')
     * @return array{
     *     statusCode: int,
     *     body: string,
     *     headers: array<string, string>,
     *     cookies: array<string, string>,
     *     effectiveUrl?: string,
     *     error: ?string
     * }
     */
    public function post(string $url, array|string $data = [], array $headers = [], array $options = []): array;
}
