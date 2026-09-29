<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Middleware;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;

/**
 * Middleware enforcing CSRF validation for mutating requests across both
 * standard form submissions and HTMX partial swaps per ADR 0005.
 */
final class CsrfMiddleware
{
    /**
     * Inspects request, ensuring token presence in session and validating mutating verbs.
     *
     * @param array<string, mixed> $session
     */
    public function process(Request $request, array &$session): ?Response
    {
        // 1. Ensure token exists in session
        if (empty($session['csrf_token'])) {
            $session['csrf_token'] = self::generateToken();
        }

        // 2. Safe read-only HTTP methods do not require CSRF token validation
        if (!$request->isMutating()) {
            return null;
        }

        // 3. Extract token from HTMX header or fallback HTML form POST field
        $clientToken = (string) ($request->getHeader('HX-CSRF-TOKEN') ?? $request->getPost('csrf_token') ?? '');
        $sessionToken = (string) $session['csrf_token'];

        // 4. Validate constant-time equality
        if ($sessionToken === '' || $clientToken === '' || !hash_equals($sessionToken, $clientToken)) {
            if ($request->isHtmx()) {
                return Response::forbidden(
                    '<div class="p-4 bg-red-100 text-red-700 rounded">Security session token expired. Please refresh the page.</div>',
                    isHtmx: true
                );
            }

            return Response::forbidden('403 Forbidden: Invalid CSRF Token', isHtmx: false);
        }

        return null;
    }

    /**
     * Generates a new 256-bit cryptographically secure token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
