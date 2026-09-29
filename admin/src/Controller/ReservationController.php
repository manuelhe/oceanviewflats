<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;

/**
 * Controller handling reservation management, real-time debounced filtering,
 * slide-over detail inspector drawer, and guest registry modal view.
 */
final class ReservationController
{
    public function __construct(
        private readonly AdminReservationRepository $repository,
        private readonly ViewRenderer $viewRenderer,
        private readonly string $publicSiteUrl = 'https://oceanviewflats.com'
    ) {
    }

    /**
     * Lists reservations with pagination, live debounced filtering, and sorting.
     *
     * @param array<string, mixed> $session
     */
    public function list(Request $request, array &$session): Response
    {
        $filters = $request->getAllQuery();
        $searchResult = $this->repository->searchReservations($filters);

        $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
            'items' => $searchResult['items'],
            'total' => $searchResult['total'],
            'page' => $searchResult['page'],
            'per_page' => $searchResult['per_page'],
            'total_pages' => $searchResult['total_pages'],
            'filters' => $filters,
        ]);

        if ($request->isHtmx()) {
            return Response::html($tableHtml);
        }

        $currentUser = $this->buildCurrentUser($session);
        $fullPageHtml = $this->viewRenderer->render(
            template: 'reservations/index.php',
            data: [
                'title' => 'Reservations - Ocean View Flats Admin',
                'currentRoute' => '/reservations',
                'currentUser' => $currentUser,
                'csrfToken' => (string) ($session['csrf_token'] ?? ''),
                'tableHtml' => $tableHtml,
                'drawerHtml' => null,
                'filters' => $filters,
            ]
        );

        return Response::html($fullPageHtml);
    }

    /**
     * Inspects a single reservation via slide-over drawer or full index view.
     *
     * @param array<string, mixed> $session
     */
    public function show(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $data = $this->repository->findReservationWithAuditTrail($uid);

        if ($data === null) {
            if ($request->isHtmx()) {
                $errorBanner = '
                    <div class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-xs flex items-center justify-center p-4">
                        <div class="bg-white rounded-xl shadow-xl p-6 max-w-sm text-center border border-gray-200">
                            <p class="text-sm font-semibold text-rose-600 mb-2">Reservation not found</p>
                            <p class="text-xs text-gray-500 mb-4">No reservation exists with UID: ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '</p>
                            <button type="button" onclick="document.getElementById(\'drawer-container\').innerHTML = \'\';" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-xs rounded-md text-gray-700 font-medium cursor-pointer">Dismiss</button>
                        </div>
                    </div>
                ';
                return Response::html($errorBanner, 404);
            }

            return Response::redirect('/reservations');
        }

        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $data['reservation'],
            'auditLogs' => $data['audit_logs'],
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
        ]);

        if ($request->isHtmx()) {
            return Response::html($drawerHtml);
        }

        // Direct browser request: render full dashboard with pre-opened drawer
        $searchResult = $this->repository->searchReservations();
        $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
            'items' => $searchResult['items'],
            'total' => $searchResult['total'],
            'page' => $searchResult['page'],
            'per_page' => $searchResult['per_page'],
            'total_pages' => $searchResult['total_pages'],
            'filters' => [],
        ]);

        $fullPageHtml = $this->viewRenderer->render(
            template: 'reservations/index.php',
            data: [
                'title' => 'Reservation ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . ' - Ocean View Flats Admin',
                'currentRoute' => '/reservations',
                'currentUser' => $this->buildCurrentUser($session),
                'csrfToken' => (string) ($session['csrf_token'] ?? ''),
                'tableHtml' => $tableHtml,
                'drawerHtml' => $drawerHtml,
                'filters' => [],
            ]
        );

        return Response::html($fullPageHtml);
    }

    /**
     * Inspects guest registry dossier or pending status modal.
     *
     * @param array<string, mixed> $session
     */
    public function showRegistry(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $resData = $this->repository->findReservationWithAuditTrail($uid);

        if ($resData === null) {
            $errorModal = '
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-xs p-4">
                    <div class="bg-white p-6 rounded-xl shadow-xl max-w-sm text-center border border-gray-200">
                        <p class="text-sm font-semibold text-rose-600 mb-2">Reservation not found</p>
                        <p class="text-xs text-gray-500 mb-4">No reservation exists with UID: ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '</p>
                        <button type="button" onclick="document.getElementById(\'modal-container\').innerHTML = \'\';" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-xs rounded-md text-gray-700 font-medium cursor-pointer">Close</button>
                    </div>
                </div>
            ';
            return Response::html($errorModal, 404);
        }

        $registry = $this->repository->findGuestRegistryByReservationUid($uid);

        $modalHtml = $this->viewRenderer->renderPartial('reservations/_registry_modal.php', [
            'reservation' => $resData['reservation'],
            'registry' => $registry,
            'publicSiteUrl' => $this->publicSiteUrl,
        ]);

        return Response::html($modalHtml);
    }

    /**
     * @param array<string, mixed> $session
     * @return array{id: int|null, name: string, email: string, role: string}
     */
    private function buildCurrentUser(array $session): array
    {
        return [
            'id' => isset($session['admin_user_id']) ? (int) $session['admin_user_id'] : null,
            'name' => (string) ($session['admin_user_name'] ?? 'Admin'),
            'email' => (string) ($session['admin_user_email'] ?? ''),
            'role' => (string) ($session['admin_user_role'] ?? 'admin'),
        ];
    }
}
