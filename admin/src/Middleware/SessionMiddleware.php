<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Middleware;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;

/**
 * Manages administrative session lifecycle, subdomain cookie isolation,
 * idle inactivity timeouts (30m), and absolute session lifetimes (8h).
 */
final class SessionMiddleware
{
    public const SESSION_NAME = 'OVF_ADMIN_SESSID';
    public const IDLE_TIMEOUT_SECONDS = 1800;    // 30 minutes
    public const MAX_LIFETIME_SECONDS = 28800;   // 8 hours

    public function __construct(
        private readonly int $idleTimeout = self::IDLE_TIMEOUT_SECONDS,
        private readonly int $maxLifetime = self::MAX_LIFETIME_SECONDS
    ) {
    }

    /**
     * Inspects and updates session state, returning a redirect response upon expiration.
     *
     * @param array<string, mixed> $session
     */
    public function process(Request $request, array &$session, ?int $currentTime = null): ?Response
    {
        $now = $currentTime ?? time();

        // 1. Check absolute max lifetime timeout
        if (isset($session['created_at']) && ($now - (int) $session['created_at'] > $this->maxLifetime)) {
            $session = [];
            return Response::redirect('/login?reason=session_expired');
        }

        // 2. Check idle inactivity timeout
        if (isset($session['last_activity']) && ($now - (int) $session['last_activity'] > $this->idleTimeout)) {
            $session = [];
            return Response::redirect('/login?reason=idle_timeout');
        }

        // 3. Initialize creation timestamp if absent
        if (empty($session['created_at'])) {
            $session['created_at'] = $now;
        }

        // 4. Touch last activity timestamp
        $session['last_activity'] = $now;

        // 5. Ensure 256-bit CSRF token is present
        if (empty($session['csrf_token'])) {
            $session['csrf_token'] = bin2hex(random_bytes(32));
        }

        return null;
    }

    /**
     * Resolves session cookie parameters enforcing subdomain isolation and strict security flags.
     *
     * @return array{lifetime: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Strict'}
     */
    public static function resolveCookieParams(?string $serverName = null, ?bool $isHttps = null): array
    {
        $domain = $serverName ?? (string) ($_SERVER['SERVER_NAME'] ?? '');
        // Strip port from domain if present
        $domain = explode(':', $domain)[0];

        $secure = $isHttps ?? (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        return [
            'lifetime' => 0,                            // Expire when browser closes
            'path' => '/',                              // Valid across all admin routes
            'domain' => $domain,                        // Scoped strictly to host/subdomain
            'secure' => $secure,                        // Secure cookie in production/HTTPS
            'httponly' => true,                         // Inaccessible to JavaScript
            'samesite' => 'Strict',                     // Block third-party transmission
        ];
    }

    /**
     * Configures cookie parameters and starts native PHP session if not already active.
     */
    public static function startNativeSession(?string $serverName = null, ?bool $isHttps = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(self::SESSION_NAME);
            session_set_cookie_params(self::resolveCookieParams($serverName, $isHttps));
            session_start();
        }
    }

    /**
     * Rotates session ID to prevent session fixation attacks upon authentication.
     */
    public static function regenerateId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Completely wipes session state and destroys native session.
     */
    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                $sessionName = (string) session_name();
                setcookie(
                    $sessionName,
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            session_destroy();
        }
    }
}
