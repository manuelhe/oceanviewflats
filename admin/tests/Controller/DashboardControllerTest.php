<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Controller;

use OceanViewFlats\Admin\Controller\DashboardController;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PHPUnit\Framework\TestCase;

final class DashboardControllerTest extends TestCase
{
    private ViewRenderer $viewRenderer;
    private DashboardController $controller;

    protected function setUp(): void
    {
        $viewsPath = dirname(__DIR__, 2) . '/src/Views';
        $this->viewRenderer = new ViewRenderer($viewsPath);
        $this->controller = new DashboardController($this->viewRenderer);
    }

    public function testIndexRendersDashboardForAuthenticatedUser(): void
    {
        $session = [
            'admin_user_id' => 99,
            'admin_user_name' => 'Operator Alice',
            'admin_user_email' => 'alice@oceanviewflats.com',
            'admin_user_role' => 'manager',
            'csrf_token' => 'session_csrf_token_xyz',
        ];
        $request = new Request('GET', '/');

        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        // Check user greeting & role
        $this->assertStringContainsString('Welcome back, Operator Alice', $body);
        $this->assertStringContainsString('manager', $body);
        $this->assertStringContainsString('Sign Out', $body);

        // Check dashboard UI sections
        $this->assertStringContainsString('Reservations & Calendar', $body);
        $this->assertStringContainsString('+ New Reservation', $body);
        $this->assertStringContainsString('href="/reservations/new"', $body);
        $this->assertStringNotContainsString('+ Manual Booking', $body);
        $this->assertStringNotContainsString('/bookings/manual', $body);
        $this->assertStringContainsString('Channel Feeds Active', $body);
        $this->assertStringContainsString('id="channel-card-container"', $body);
        $this->assertStringContainsString('Sync Now', $body);
        $this->assertStringContainsString('hx-post="/channel-sync"', $body);
        $this->assertStringContainsString('Keypad PIN Integrations Ready', $body);

        // Check CSRF token inclusion
        $this->assertStringContainsString('session_csrf_token_xyz', $body);
    }

    public function testIndexHandlesDefaultUserFallbackWhenSessionFieldsMissing(): void
    {
        $session = [];
        $request = new Request('GET', '/');

        $response = $this->controller->index($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();

        // Default fallbacks: 'Admin' and 'admin'
        $this->assertStringContainsString('Welcome back, Admin', $body);
        $this->assertStringContainsString('admin', $body);
    }

    public function testIndexAdheresToUniformCallableSignature(): void
    {
        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/');

        $invoker = function (callable $handler, Request $req, array &$sess): Response {
            return $handler($req, $sess);
        };

        $response = $invoker([$this->controller, 'index'], $request, $session);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLayoutIncludesHtmxConfigFor422Swapping(): void
    {
        $session = ['admin_user_id' => 1];
        $request = new Request('GET', '/');

        $response = $this->controller->index($request, $session);
        $body = $response->getBody();

        // Meta tag for HTMX 2.x responseHandling permitting 422 swapping
        $this->assertStringContainsString('name="htmx-config"', $body);
        $this->assertStringContainsString('"code": "422"', $body);
        $this->assertStringContainsString('"swap": true', $body);

        // Global htmx:beforeSwap listener for resilient 422 swapping and error suppression
        $this->assertStringContainsString('htmx:beforeSwap', $body);
        $this->assertStringContainsString('422', $body);
        $this->assertStringContainsString('shouldSwap', $body);
    }
}

