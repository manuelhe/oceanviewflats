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
     * @var array<string, array<string, callable(Request, array<string, mixed>&): Response>>
     */
    private array $customRoutes = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $authService,
        private readonly ViewRenderer $viewRenderer,
        private readonly SessionMiddleware $sessionMiddleware,
        private readonly CsrfMiddleware $csrfMiddleware,
        private readonly AuthMiddleware $authMiddleware
    ) {
    }

    /**
     * Registers a custom route handler for future expansion (e.g. #33, #34).
     *
     * @param callable(Request, array<string, mixed>&): Response $handler
     */
    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $this->customRoutes[strtoupper($method)][$path] = $handler;
    }

    /**
     * Dispatches an incoming request through the security middleware pipeline to its route handler.
     *
     * @param array<string, mixed> $session
     */
    public function dispatch(Request $request, array &$session): Response
    {
        // 1. Session Lifecycle Middleware
        $sessionResponse = $this->sessionMiddleware->process($request, $session);
        if ($sessionResponse !== null) {
            return $sessionResponse;
        }

        // 2. CSRF Protection Middleware
        $csrfResponse = $this->csrfMiddleware->process($request, $session);
        if ($csrfResponse !== null) {
            return $csrfResponse;
        }

        // 3. Authentication & Authorization Middleware
        $authResponse = $this->authMiddleware->process($request, $session);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $method = $request->getMethod();
        $uri = $request->getUri();

        // Check custom registered routes first
        if (isset($this->customRoutes[$method][$uri])) {
            return ($this->customRoutes[$method][$uri])($request, $session);
        }

        // Core routes
        if ($method === 'GET' && $uri === '/login') {
            return $this->handleGetLogin($request, $session);
        }

        if ($method === 'POST' && $uri === '/login') {
            return $this->handlePostLogin($request, $session);
        }

        if ($method === 'GET' && $uri === '/logout') {
            return $this->handleGetLogout($request, $session);
        }

        if ($method === 'GET' && $uri === '/') {
            return $this->handleGetDashboard($request, $session);
        }

        return Response::html('<div class="p-8 text-center"><h1 class="text-2xl font-bold text-gray-800">404 Not Found</h1><p class="text-gray-500 mt-2">The requested administrative page could not be located.</p></div>', 404);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleGetLogin(Request $request, array &$session): Response
    {
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
        if (!empty($session['admin_user_id'])) {
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
