<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Middleware;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use PHPUnit\Framework\TestCase;

final class SessionMiddlewareTest extends TestCase
{
    private SessionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new SessionMiddleware();
    }

    public function testInitializesSessionTimestampsAndCsrfToken(): void
    {
        $session = [];
        $request = new Request('GET', '/');

        $response = $this->middleware->process($request, $session, 1000);

        $this->assertNull($response);
        $this->assertSame(1000, $session['created_at']);
        $this->assertSame(1000, $session['last_activity']);
        $this->assertNotEmpty($session['csrf_token']);
        $this->assertSame(64, strlen((string) $session['csrf_token'])); // 32 bytes in hex = 64 chars
    }

    public function testUpdatesLastActivityOnValidActiveSession(): void
    {
        $session = [
            'admin_user_id' => 1,
            'created_at' => 1000,
            'last_activity' => 1200,
            'csrf_token' => 'a' . str_repeat('0', 63),
        ];
        $request = new Request('GET', '/reservations');

        // Request at timestamp 1500 (300s later, within 1800s idle window)
        $response = $this->middleware->process($request, $session, 1500);

        $this->assertNull($response);
        $this->assertSame(1000, $session['created_at']);
        $this->assertSame(1500, $session['last_activity']);
        $this->assertSame(1, $session['admin_user_id']);
    }

    public function testIdleTimeoutDestroysSessionAndRedirects(): void
    {
        $session = [
            'admin_user_id' => 1,
            'created_at' => 1000,
            'last_activity' => 1000,
            'csrf_token' => 'existing_token',
        ];
        $request = new Request('GET', '/reservations');

        // Request at timestamp 2801 (1801s after last activity -> idle timeout)
        $response = $this->middleware->process($request, $session, 2801);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?reason=idle_timeout', $response->getHeaders()['Location']);
        // Session should be wiped
        $this->assertEmpty($session);
    }

    public function testAbsoluteMaxLifetimeDestroysSessionAndRedirects(): void
    {
        $session = [
            'admin_user_id' => 1,
            'created_at' => 1000,
            'last_activity' => 29000, // recent activity
            'csrf_token' => 'existing_token',
        ];
        $request = new Request('GET', '/reservations');

        // Request at timestamp 29801 (28801s after created_at -> 8 hour absolute expiry)
        $response = $this->middleware->process($request, $session, 29801);

        $this->assertNotNull($response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login?reason=session_expired', $response->getHeaders()['Location']);
        // Session should be wiped
        $this->assertEmpty($session);
    }

    public function testIdleTimeoutWithHtmxReturns401AndHxRedirectHeader(): void
    {
        $session = [
            'admin_user_id' => 1,
            'created_at' => 1000,
            'last_activity' => 1000,
        ];
        $request = new Request(
            method: 'GET',
            uri: '/reservations/rows',
            server: ['HTTP_HX_REQUEST' => 'true']
        );

        $response = $this->middleware->process($request, $session, 2801);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('/login?reason=idle_timeout', $response->getHeaders()['HX-Redirect']);
        $this->assertEmpty($session);
    }

    public function testAbsoluteMaxLifetimeWithHtmxReturns401AndHxRedirectHeader(): void
    {
        $session = [
            'admin_user_id' => 1,
            'created_at' => 1000,
            'last_activity' => 29000,
        ];
        $request = new Request(
            method: 'GET',
            uri: '/reservations/rows',
            server: ['HTTP_HX_REQUEST' => 'true']
        );

        $response = $this->middleware->process($request, $session, 29801);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('/login?reason=session_expired', $response->getHeaders()['HX-Redirect']);
        $this->assertEmpty($session);
    }

    public function testGetCookieParametersResolution(): void
    {
        $params = SessionMiddleware::resolveCookieParams('admin.oceanviewflats.com', true);

        $this->assertSame(0, $params['lifetime']);
        $this->assertSame('/', $params['path']);
        $this->assertSame('admin.oceanviewflats.com', $params['domain']);
        $this->assertTrue($params['secure']);
        $this->assertTrue($params['httponly']);
        $this->assertSame('Strict', $params['samesite']);
    }
}
