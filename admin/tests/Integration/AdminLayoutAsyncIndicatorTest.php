<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Integration;

use OceanViewFlats\Admin\AdminApp;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Tests\Support\AdminDatabaseTestHelper;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration test suite for Ticket #123:
 * Global Animated Top Progress Bar with Debounced HTMX Request Counter.
 *
 * Verifies that all primary administrative authenticated pages render:
 * 1. Pinned top progress bar (#global-progress-bar) with Tailwind classes and ARIA attributes.
 * 2. Keyframe animations for indeterminate shimmer and prefers-reduced-motion media query.
 * 3. Preserved explicit .htmx-indicator rules.
 * 4. Client-side HTMX lifecycle script handling htmx:beforeRequest and htmx:afterRequest
 *    with an activeRequests counter, 150ms debounce threshold, and width transitions.
 */
final class AdminLayoutAsyncIndicatorTest extends TestCase
{
    private PDO $pdo;
    private AdminApp $app;

    /**
     * @var array<string, mixed>
     */
    private array $authenticatedSession;

    protected function setUp(): void
    {
        $this->pdo = AdminDatabaseTestHelper::createDatabaseWithDefaultAdmin();
        $this->app = AdminApp::createDefault($this->pdo);

        $this->authenticatedSession = [
            'admin_user_id' => 1,
            'admin_user_name' => 'Manuel Admin',
            'admin_user_email' => 'admin@oceanviewflats.com',
            'admin_user_role' => 'admin',
            'csrf_token' => 'secure_csrf_token_async_indicator_test',
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function authenticatedRoutesProvider(): array
    {
        return [
            'Dashboard Hub' => ['/'],
            'Reservations Ledger' => ['/reservations'],
            'Property Rates' => ['/rates'],
            'Calendar Maintenance Blocks' => ['/calendar-blocks'],
        ];
    }

    #[DataProvider('authenticatedRoutesProvider')]
    public function testAuthenticatedRoutesRenderGlobalProgressBarElement(string $route): void
    {
        $request = new Request('GET', $route);
        $response = $this->app->handle($request, $this->authenticatedSession);

        $this->assertSame(200, $response->getStatusCode(), "Expected HTTP 200 for authenticated GET {$route}");
        $html = $response->getBody();

        // 1. Progress bar element existence and ID
        $this->assertStringContainsString('id="global-progress-bar"', $html);

        // 2. Accessibility attributes
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('aria-label="Loading"', $html);

        // 3. Tailwind styling and initial state
        $this->assertStringContainsString('fixed top-0 left-0 h-1 bg-indigo-600 z-50 pointer-events-none transition-all duration-300 ease-out opacity-0', $html);
        $this->assertStringContainsString('style="width: 0%;"', $html);
    }

    #[DataProvider('authenticatedRoutesProvider')]
    public function testAuthenticatedRoutesIncludeHtmxLifecycleScript(string $route): void
    {
        $request = new Request('GET', $route);
        $response = $this->app->handle($request, $this->authenticatedSession);

        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getBody();

        // 1. HTMX lifecycle listeners
        $this->assertStringContainsString('htmx:beforeRequest', $html);
        $this->assertStringContainsString('htmx:afterRequest', $html);

        // 2. Active requests counter
        $this->assertStringContainsString('activeRequests', $html);

        // 3. 150ms debounce logic
        $this->assertStringContainsString('150', $html);
        $this->assertStringContainsString('150ms debounce', $html);

        // 4. Smooth progression markers (25% -> 60% -> 85% -> 100% -> 0%)
        $this->assertStringContainsString("25%", $html);
        $this->assertStringContainsString("60%", $html);
        $this->assertStringContainsString("85%", $html);
        $this->assertStringContainsString("100%", $html);
        $this->assertStringContainsString("0%", $html);
    }

    #[DataProvider('authenticatedRoutesProvider')]
    public function testAuthenticatedRoutesIncludeShimmerAndAccessibilityStyles(string $route): void
    {
        $request = new Request('GET', $route);
        $response = $this->app->handle($request, $this->authenticatedSession);

        $this->assertSame(200, $response->getStatusCode());
        $html = $response->getBody();

        // 1. Shimmer animation keyframes
        $this->assertStringContainsString('@keyframes progress-shimmer', $html);
        $this->assertStringContainsString('#global-progress-bar.loading', $html);
        $this->assertStringContainsString('.htmx-request #global-progress-bar', $html);

        // 2. Reduced motion media query
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
        $this->assertStringContainsString('animation: none !important', $html);

        // 3. Preserved existing .htmx-indicator rules
        $this->assertStringContainsString('.htmx-indicator { display: none; }', $html);
        $this->assertStringContainsString('.htmx-request .htmx-indicator', $html);
    }

    #[DataProvider('authenticatedRoutesProvider')]
    public function testUnauthenticatedRequestsRedirectToLogin(string $route): void
    {
        $request = new Request('GET', $route);
        $emptySession = [];
        $response = $this->app->handle($request, $emptySession);

        $this->assertSame(302, $response->getStatusCode(), "Unauthenticated GET {$route} must redirect");
        $this->assertSame('/login', $response->getHeader('Location'));
    }
}
