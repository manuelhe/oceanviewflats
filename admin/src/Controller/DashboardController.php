<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Views\ViewRenderer;

/**
 * Controller handling the administrative dashboard overview and home interface.
 */
final class DashboardController
{
    public function __construct(
        private readonly ViewRenderer $viewRenderer
    ) {
    }

    /**
     * Renders the administrative dashboard overview screen.
     *
     * @param array<string, mixed> $session
     */
    public function index(Request $request, array &$session): Response
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
