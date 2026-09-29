<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Auth\AuthService;
use OceanViewFlats\Admin\Auth\LoginResult;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use OceanViewFlats\Admin\Views\ViewRenderer;
use PDO;

/**
 * Controller handling administrative authentication workflows, brute-force defenses,
 * session establishment, and logout session invalidation.
 */
final class AuthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthService $authService,
        private readonly ViewRenderer $viewRenderer
    ) {
    }

    /**
     * Presents the administrative login screen or redirects if already authenticated.
     *
     * @param array<string, mixed> $session
     */
    public function showLogin(Request $request, array &$session): Response
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
     * Processes administrative credential authentication, brute-force defenses, session initialization, and audit logging.
     *
     * @param array<string, mixed> $session
     */
    public function login(Request $request, array &$session): Response
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
     * Invalidates the active administrative session, logs the logout audit event, and redirects to the sign-in view.
     *
     * @param array<string, mixed> $session
     */
    public function logout(Request $request, array &$session): Response
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
}
