<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Http;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\LoginResult;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;

/**
 * Front-controller router and request dispatcher for the Admin interface.
 */
final class Router
{
    /**
     * Fast constant-time lookup for static routes: [METHOD => [normalizedPath => handler]]
     *
     * @var array<string, array<string, callable(Request, array<string, mixed>&): Response>>
     */
    private array $staticRoutes = [];

    /**
     * Dynamic regex-compiled routes: [METHOD => list of route definitions]
     *
     * @var array<string, list<array{pattern: string, handler: callable(Request, array<string, mixed>&): Response, tokens: list<string>}>>
     */
    private array $dynamicRoutes = [];

    public function __construct(
        private readonly ?PDO $pdo = null,
        private readonly ?AuthService $authService = null,
        private readonly ?ViewRenderer $viewRenderer = null,
        private readonly ?SessionMiddleware $sessionMiddleware = null,
        private readonly ?CsrfMiddleware $csrfMiddleware = null,
        private readonly ?AuthMiddleware $authMiddleware = null
    ) {
        if ($this->pdo !== null && $this->authService !== null && $this->viewRenderer !== null) {
            $this->registerCoreRoutes();
        }
    }

    /**
     * Registers a custom route handler for future expansion (e.g. #33, #34).
     * Backward-compatible alias for addRoute.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $this->addRoute($method, $path, $handler);
    }

    /**
     * Adds a route for a specific HTTP method and path pattern.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function addRoute(string $method, string $path, callable $handler): self
    {
        $method = strtoupper(trim($method));
        $normalizedPath = $this->normalizePath($path);

        if ($this->isDynamic($normalizedPath)) {
            $compiled = $this->compileRoutePattern($normalizedPath);
            $this->dynamicRoutes[$method][] = [
                'pattern' => $compiled['pattern'],
                'handler' => $handler,
                'tokens' => $compiled['tokens'],
            ];
        } else {
            $this->staticRoutes[$method][$normalizedPath] = $handler;
        }

        return $this;
    }

    /**
     * Fluent helper for registering GET routes.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function get(string $path, callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Fluent helper for registering POST routes.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function post(string $path, callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Fluent helper for registering DELETE routes.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function delete(string $path, callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Fluent helper for registering PUT routes.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function put(string $path, callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Fluent helper for registering PATCH routes.
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function patch(string $path, callable $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Dispatches an incoming request through the security middleware pipeline to its route handler.
     *
     * @param array<string, mixed> $session
     */
    public function dispatch(Request $request, array &$session): Response
    {
        // 1. Session Lifecycle Middleware
        if ($this->sessionMiddleware !== null) {
            $sessionResponse = $this->sessionMiddleware->process($request, $session);
            if ($sessionResponse !== null) {
                return $sessionResponse;
            }
        }

        // 2. CSRF Protection Middleware
        if ($this->csrfMiddleware !== null) {
            $csrfResponse = $this->csrfMiddleware->process($request, $session);
            if ($csrfResponse !== null) {
                return $csrfResponse;
            }
        }

        // 3. Authentication & Authorization Middleware
        if ($this->authMiddleware !== null) {
            $authResponse = $this->authMiddleware->process($request, $session);
            if ($authResponse !== null) {
                return $authResponse;
            }
        }

        $method = strtoupper($request->getMethod());
        $normalizedUri = $this->normalizePath($request->getUri());

        // 4. Fast constant-time lookup for static routes
        if (isset($this->staticRoutes[$method][$normalizedUri])) {
            return ($this->staticRoutes[$method][$normalizedUri])($request, $session);
        }

        // 5. Dynamic regex-compiled routes
        if (isset($this->dynamicRoutes[$method])) {
            foreach ($this->dynamicRoutes[$method] as $route) {
                if (preg_match($route['pattern'], $normalizedUri, $matches) === 1) {
                    $params = [];
                    foreach ($matches as $key => $val) {
                        if (is_string($key)) {
                            $params[$key] = rawurldecode($val);
                        }
                    }
                    $matchedRequest = $request->withAttributes($params);
                    return ($route['handler'])($matchedRequest, $session);
                }
            }
        }

        // 6. Check if URI matches other HTTP methods (HTTP 405 Method Not Allowed)
        $allowedMethods = $this->getAllowedMethodsForUri($normalizedUri, $method);
        if (!empty($allowedMethods)) {
            return Response::html(
                '<div class="p-8 text-center"><h1 class="text-2xl font-bold text-gray-800">405 Method Not Allowed</h1><p class="text-gray-500 mt-2">The requested method is not allowed for this URL.</p></div>',
                405,
                ['Allow' => implode(', ', $allowedMethods)]
            );
        }

        // 7. No match across any HTTP method (HTTP 404 Not Found)
        return Response::html(
            '<div class="p-8 text-center"><h1 class="text-2xl font-bold text-gray-800">404 Not Found</h1><p class="text-gray-500 mt-2">The requested administrative page could not be located.</p></div>',
            404
        );
    }

    /**
     * Returns static routes lookup map for inspection.
     *
     * @return array<string, array<string, callable(Request, array<string, mixed>&): Response>>
     */
    public function getStaticRoutes(): array
    {
        return $this->staticRoutes;
    }

    /**
     * Returns dynamic compiled routes list for inspection.
     *
     * @return array<string, list<array{pattern: string, handler: callable(Request, array<string, mixed>&): Response, tokens: list<string>}>>
     */
    public function getDynamicRoutes(): array
    {
        return $this->dynamicRoutes;
    }

    /**
     * Normalizes a URI or route pattern by stripping query/hash and trailing slashes,
     * ensuring leading slash, while preserving root '/'.
     */
    private function normalizePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        $trimmed = trim($path);
        if ($trimmed === '' || $trimmed === '/') {
            return '/';
        }

        if (!str_starts_with($trimmed, '/')) {
            $trimmed = '/' . $trimmed;
        }

        $normalized = rtrim($trimmed, '/');
        return $normalized !== '' ? $normalized : '/';
    }

    /**
     * Checks if a path pattern contains dynamic {param} placeholders.
     */
    private function isDynamic(string $path): bool
    {
        return str_contains($path, '{') && preg_match('/\{[a-zA-Z0-9_]+\}/', $path) === 1;
    }

    /**
     * Compiles a path with {param} placeholders into a regular expression with named capture groups.
     *
     * @return array{pattern: string, tokens: list<string>}
     */
    private function compileRoutePattern(string $path): array
    {
        $tokens = [];
        $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [
                'pattern' => '#^' . preg_quote($path, '#') . '$#',
                'tokens' => [],
            ];
        }

        $regexParts = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $part, $tokenMatch) === 1) {
                $paramName = $tokenMatch[1];
                $tokens[] = $paramName;
                $regexParts[] = '(?P<' . $paramName . '>[^/]+)';
            } else {
                $regexParts[] = preg_quote($part, '#');
            }
        }

        return [
            'pattern' => '#^' . implode('', $regexParts) . '$#',
            'tokens' => $tokens,
        ];
    }

    /**
     * Resolves all HTTP methods that can handle the given normalized URI, excluding any specified method.
     *
     * @return list<string>
     */
    private function getAllowedMethodsForUri(string $normalizedUri, string $excludeMethod = ''): array
    {
        $allowed = [];

        foreach ($this->staticRoutes as $method => $paths) {
            if ($method !== $excludeMethod && isset($paths[$normalizedUri])) {
                $allowed[] = $method;
            }
        }

        foreach ($this->dynamicRoutes as $method => $routes) {
            if ($method !== $excludeMethod && !in_array($method, $allowed, true)) {
                foreach ($routes as $route) {
                    if (preg_match($route['pattern'], $normalizedUri) === 1) {
                        $allowed[] = $method;
                        break;
                    }
                }
            }
        }

        sort($allowed);

        return $allowed;
    }

    /**
     * Registers default built-in core routes for backward-compatibility.
     */
    private function registerCoreRoutes(): void
    {
        $this->get('/login', $this->handleGetLogin(...));
        $this->post('/login', $this->handlePostLogin(...));
        $this->get('/logout', $this->handleGetLogout(...));
        $this->get('/', $this->handleGetDashboard(...));
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleGetLogin(Request $request, array &$session): Response
    {
        if ($this->viewRenderer === null) {
            throw new \LogicException('ViewRenderer is required to render login view.');
        }

        if (!empty($session['admin_user_id'])) {
            return Response::redirect('/');
        }

        $reason = (string) $request->getQuery('reason', '');
        $csrfToken = (string) ($session['csrf_token'] ?? '');

        $html = $this->viewRenderer->render(
            template: 'auth/login.php',
            data: [
                'title' => 'Sign In - Ocean View Flats Admin',
                'csrfToken' => $csrfToken,
                'reason' => $reason !== '' ? $reason : null,
                'error' => null,
                'email' => '',
                'currentUser' => null,
            ]
        );

        return Response::html($html);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handlePostLogin(Request $request, array &$session): Response
    {
        if ($this->pdo === null || $this->authService === null || $this->viewRenderer === null) {
            throw new \LogicException('PDO, AuthService, and ViewRenderer are required to handle login.');
        }

        $email = (string) $request->getPost('email', '');
        $password = (string) $request->getPost('password', '');
        $ip = $request->getClientIp();

        $result = $this->authService->authenticate($email, $password, $ip);

        if ($result->isSuccess()) {
            SessionMiddleware::regenerateId();

            $user = $result->getUser() ?? [];
            $adminUserId = (int) ($user['id'] ?? 0);
            $session['admin_user_id'] = $adminUserId;
            $session['admin_user_name'] = (string) ($user['name'] ?? 'Admin');
            $session['admin_user_email'] = (string) ($user['email'] ?? $email);
            $session['admin_user_role'] = (string) ($user['role'] ?? 'admin');

            // Rotate CSRF token upon successful authentication per Spec
            $session['csrf_token'] = CsrfMiddleware::generateToken();

            AuditLogger::log(
                pdo: $this->pdo,
                action: 'login_success',
                entityType: 'admin_user',
                entityId: (string) $adminUserId,
                before: null,
                after: ['email' => $email],
                adminUserId: $adminUserId,
                ipAddress: $ip,
                userAgent: (string) $request->getServer('HTTP_USER_AGENT', '')
            );

            return Response::redirect('/');
        }

        // Determine error message based on failure classification
        $errorMessage = match ($result->getStatus()) {
            LoginResult::STATUS_ACCOUNT_LOCKED => "Account temporarily locked. Please try again in {$result->getLockoutMinutes()} minutes.",
            LoginResult::STATUS_RATE_LIMITED => "Too many failed login attempts from this network. Please retry in {$result->getRetryAfterSeconds()} seconds.",
            LoginResult::STATUS_ACCOUNT_DISABLED => 'This account has been deactivated. Please contact an administrator.',
            default => 'The email address or password entered is incorrect.',
        };

        AuditLogger::log(
            pdo: $this->pdo,
            action: 'login_failure',
            entityType: 'auth',
            entityId: $email,
            before: null,
            after: ['status' => $result->getStatus()],
            adminUserId: null,
            ipAddress: $ip,
            userAgent: (string) $request->getServer('HTTP_USER_AGENT', '')
        );

        $statusCode = $result->getStatus() === LoginResult::STATUS_RATE_LIMITED ? 429 : 401;
        $headers = [];
        if ($result->getStatus() === LoginResult::STATUS_RATE_LIMITED) {
            $retryAfter = $result->getRetryAfterSeconds() > 0 ? $result->getRetryAfterSeconds() : 900;
            $headers['Retry-After'] = (string) $retryAfter;
        }

        $csrfToken = (string) ($session['csrf_token'] ?? '');

        $html = $this->viewRenderer->render(
            template: 'auth/login.php',
            data: [
                'title' => 'Sign In - Ocean View Flats Admin',
                'csrfToken' => $csrfToken,
                'reason' => null,
                'error' => $errorMessage,
                'email' => $email,
                'currentUser' => null,
            ]
        );

        return Response::html($html, $statusCode, $headers);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleGetLogout(Request $request, array &$session): Response
    {
        if ($this->pdo !== null && !empty($session['admin_user_id'])) {
            $adminUserId = (int) $session['admin_user_id'];
            AuditLogger::log(
                pdo: $this->pdo,
                action: 'logout',
                entityType: 'admin_user',
                entityId: (string) $adminUserId,
                before: null,
                after: null,
                adminUserId: $adminUserId,
                ipAddress: $request->getClientIp(),
                userAgent: (string) $request->getServer('HTTP_USER_AGENT', '')
            );
        }

        SessionMiddleware::destroySession();
        $session = [];

        return Response::redirect('/login?reason=logged_out');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleGetDashboard(Request $request, array &$session): Response
    {
        if ($this->viewRenderer === null) {
            throw new \LogicException('ViewRenderer is required to render dashboard view.');
        }

        $currentUser = [
            'id' => $session['admin_user_id'] ?? null,
            'name' => $session['admin_user_name'] ?? 'Admin',
            'email' => $session['admin_user_email'] ?? '',
            'role' => $session['admin_user_role'] ?? 'admin',
        ];

        $html = $this->viewRenderer->render(
            template: 'dashboard/index.php',
            data: [
                'title' => 'Dashboard - Ocean View Flats Admin',
                'currentRoute' => '/',
                'currentUser' => $currentUser,
                'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            ]
        );

        return Response::html($html);
    }
}
