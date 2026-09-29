<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Middleware;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new CsrfMiddleware();
    }

    public function testGeneratesCsrfTokenIfMissing(): void
    {
        $session = [];
        $request = new Request('GET', '/login');

        $response = $this->middleware->process($request, $session);

        $this->assertNull($response);
        $this->assertNotEmpty($session['csrf_token']);
        $this->assertSame(64, strlen((string) $session['csrf_token']));
    }

    public function testAllowsSafeMethodsWithoutToken(): void
    {
        $session = ['csrf_token' => 'secure_token_123'];
        $request = new Request('GET', '/reservations');

        $response = $this->middleware->process($request, $session);

        $this->assertNull($response);
    }

    public function testAllowsMutatingMethodWithValidHeaderToken(): void
    {
        $session = ['csrf_token' => 'valid_token_xyz'];
        $request = new Request(
            method: 'POST',
            uri: '/reservations/123/pin-override',
            server: ['HTTP_HX_CSRF_TOKEN' => 'valid_token_xyz']
        );

        $response = $this->middleware->process($request, $session);

        $this->assertNull($response);
    }

    public function testAllowsMutatingMethodWithValidPostToken(): void
    {
        $session = ['csrf_token' => 'valid_token_xyz'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: ['csrf_token' => 'valid_token_xyz']
        );

        $response = $this->middleware->process($request, $session);

        $this->assertNull($response);
    }

    public function testRejectsMutatingMethodWithMissingTokenForStandardRequest(): void
    {
        $session = ['csrf_token' => 'valid_token_xyz'];
        $request = new Request(
            method: 'POST',
            uri: '/login',
            post: []
        );

        $response = $this->middleware->process($request, $session);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('403 Forbidden: Invalid CSRF Token', $response->getBody());
    }

    public function testRejectsMutatingMethodWithInvalidTokenForHtmxRequest(): void
    {
        $session = ['csrf_token' => 'valid_token_xyz'];
        $request = new Request(
            method: 'POST',
            uri: '/reservations/123/pin-override',
            server: [
                'HTTP_HX_REQUEST' => 'true',
                'HTTP_HX_CSRF_TOKEN' => 'wrong_token',
            ]
        );

        $response = $this->middleware->process($request, $session);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('none', $response->getHeaders()['HX-Reswap']);
        $this->assertStringContainsString('Security session token expired. Please refresh the page.', $response->getBody());
    }

    public function testRejectsMutatingMethodWhenSessionTokenIsEmpty(): void
    {
        $session = [];
        $request = new Request(
            method: 'DELETE',
            uri: '/reservations/123',
            post: ['csrf_token' => 'some_token']
        );

        $response = $this->middleware->process($request, $session);

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
    }
}
