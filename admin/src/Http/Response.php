<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Http;

/**
 * Lightweight HTTP response value object with HTMX and redirection helpers.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode = 200,
        private readonly string $body = '',
        private readonly array $headers = []
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $html, int $statusCode = 200, array $headers = []): self
    {
        return new self($statusCode, $html, array_merge([
            'Content-Type' => 'text/html; charset=UTF-8',
        ], $headers));
    }

    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self($statusCode, '', [
            'Location' => $url,
        ]);
    }

    public static function htmxRedirect(string $url, int $statusCode = 200): self
    {
        return new self($statusCode, '', [
            'HX-Redirect' => $url,
        ]);
    }

    public static function htmxUnauthorized(string $loginUrl = '/login'): self
    {
        return new self(401, '', [
            'HX-Redirect' => $loginUrl,
        ]);
    }

    public static function forbidden(string $body = '403 Forbidden', bool $isHtmx = false): self
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($isHtmx) {
            $headers['HX-Reswap'] = 'none';
        }

        return new self(403, $body, $headers);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isRedirect(): bool
    {
        return isset($this->headers['Location']) || isset($this->headers['HX-Redirect']);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->statusCode);
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        echo $this->body;
    }
}
