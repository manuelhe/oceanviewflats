<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Http;

/**
 * Lightweight HTTP request value object.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($rawUri, PHP_URL_PATH);

        return new self(
            method: $method,
            uri: $path !== '' ? $path : '/',
            query: $_GET,
            post: $_POST,
            server: $_SERVER,
            cookies: $_COOKIE
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllQuery(): array
    {
        return $this->query;
    }

    public function getPost(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllPost(): array
    {
        return $this->post;
    }

    public function getHeader(string $name, mixed $default = null): mixed
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$serverKey] ?? $default;
    }

    public function getServer(string $name, mixed $default = null): mixed
    {
        return $this->server[$name] ?? $default;
    }

    public function isHtmx(): bool
    {
        return !empty($this->server['HTTP_HX_REQUEST']);
    }

    public function getClientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function getCookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    public function isMutating(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }
}
