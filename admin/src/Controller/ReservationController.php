<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Repository\AdminReservationRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Access\DoorCodeGenerator;
use OceanViewFlats\Domain\Fulfillment\ConfirmationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\EmailSenderInterface;
use OceanViewFlats\Domain\Quote\QuoteEngineInterface;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use Throwable;

/**
 * Controller handling reservation management, real-time debounced filtering,
 * slide-over detail inspector drawer, manual booking creation, PIN overrides,
 * and guest registry administration.
 */
final class ReservationController
{
    public function __construct(
        private readonly AdminReservationRepository $repository,
        private readonly ViewRenderer $viewRenderer,
        private readonly AuditLogger $auditLogger,
        private readonly ReservationLedgerInterface $ledger,
        private readonly QuoteEngineInterface $quoteEngine,
        private readonly ConfirmationEmailRendererInterface $emailRenderer,
        private readonly EmailSenderInterface $emailSender,
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

        if ($request->isHtmx()) {
            $searchResult = $this->repository->searchReservations($filters);
            $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
                'items' => $searchResult['items'],
                'total' => $searchResult['total'],
                'page' => $searchResult['page'],
                'per_page' => $searchResult['per_page'],
                'total_pages' => $searchResult['total_pages'],
                'filters' => $filters,
            ]);
            return Response::html($tableHtml);
        }

        return Response::html($this->renderFullDashboard($session, $filters));
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
        return Response::html($this->renderFullDashboard(
            session: $session,
            drawerHtml: $drawerHtml,
            title: 'Reservation ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . ' - Ocean View Flats Admin'
        ));
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
     * Renders the modal dialog for creating a manual reservation (HTMX or direct deep-link).
     *
     * @param array<string, mixed> $session
     */
    public function newReservation(Request $request, array &$session): Response
    {
        $modalHtml = $this->viewRenderer->renderPartial('reservations/_create_modal.php', [
            'errorMessage' => null,
        ]);

        if ($request->isHtmx()) {
            return Response::html($modalHtml);
        }

        return Response::html($this->renderFullDashboard(
            session: $session,
            filters: $request->getAllQuery(),
            modalHtml: $modalHtml,
            title: 'New Reservation - Ocean View Flats Admin'
        ));
    }

    /**
     * Real-time quote and availability checker for manual booking creation form.
     */
    public function quotePreview(Request $request): Response
    {
        $body = $request->getAllPost();
        $propertyId = (string) ($body['property_id'] ?? '');
        $checkIn = (string) ($body['check_in'] ?? '');
        $checkOut = (string) ($body['check_out'] ?? '');
        $source = (string) ($body['source'] ?? 'manual_override');

        if ($propertyId === '' || $checkIn === '' || $checkOut === '' || $checkIn >= $checkOut) {
            $html = $this->viewRenderer->renderPartial('reservations/_quote_preview.php', [
                'isAvailable' => false,
                'conflictReasons' => ['Please select check-in and check-out dates (check-out must be after check-in).'],
                'quote' => null,
                'source' => $source,
                'defaultPrice' => 0.0,
            ]);
            return Response::html($html);
        }

        $isAvailable = $this->ledger->isAvailable($propertyId, $checkIn, $checkOut);
        $conflictReasons = $isAvailable ? [] : $this->ledger->getConflictReasons($propertyId, $checkIn, $checkOut);
        $quote = null;
        $defaultPrice = 0.0;

        if ($isAvailable) {
            try {
                $quote = $this->quoteEngine->quote($propertyId, $checkIn, $checkOut);
                $defaultPrice = ($source === 'owner_stay') ? 0.0 : $quote->totalCop;
            } catch (Throwable) {
                $isAvailable = false;
                $conflictReasons[] = 'Failed to calculate rate quote for specified dates.';
            }
        }

        $html = $this->viewRenderer->renderPartial('reservations/_quote_preview.php', [
            'isAvailable' => $isAvailable,
            'conflictReasons' => $conflictReasons,
            'quote' => $quote,
            'source' => $source,
            'defaultPrice' => $defaultPrice,
        ]);

        return Response::html($html);
    }

    /**
     * Creates a manual reservation with gateway bypass and optional instant guest registry pre-completion.
     *
     * @param array<string, mixed> $session
     */
    public function createManual(Request $request, array &$session): Response
    {
        $body = $request->getAllPost();
        $propertyId = trim((string) ($body['property_id'] ?? ''));
        $checkIn = trim((string) ($body['check_in'] ?? ''));
        $checkOut = trim((string) ($body['check_out'] ?? ''));
        $source = trim((string) ($body['source'] ?? 'manual_override'));
        $totalPriceRaw = $body['total_price'] ?? null;
        $guestName = trim((string) ($body['guest_name'] ?? ''));
        $guestEmail = trim((string) ($body['guest_email'] ?? ''));
        $guestPhone = trim((string) ($body['guest_phone'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        $preMarkRegistry = isset($body['pre_mark_registry']) && (string) $body['pre_mark_registry'] === '1';
        $sendConfirmationEmail = isset($body['send_confirmation_email']) && (string) $body['send_confirmation_email'] === '1';

        $totalPrice = is_numeric($totalPriceRaw) ? (float) $totalPriceRaw : -1.0;

        // Validation
        if (!in_array($propertyId, ['1606', '1707'], true)) {
            return $this->renderCreateError($request, 'Please select a valid property (1606 or 1707).');
        }

        if ($checkIn === '' || $checkOut === '' || $checkIn >= $checkOut) {
            return $this->renderCreateError($request, 'Check-out date must be strictly after check-in date.');
        }

        if ($guestName === '') {
            return $this->renderCreateError($request, 'Guest name is required.');
        }

        if ($guestEmail === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->renderCreateError($request, 'A valid guest email address is required.');
        }

        if ($guestPhone === '') {
            return $this->renderCreateError($request, 'Guest phone number is required.');
        }

        if ($totalPrice < 0.0) {
            return $this->renderCreateError($request, 'Total price cannot be negative.');
        }

        // Ledger conflict check
        if (!$this->ledger->isAvailable($propertyId, $checkIn, $checkOut)) {
            return $this->renderCreateError($request, 'Selected dates conflict with an existing reservation or channel block.');
        }

        // Generate UID and random Door Code
        $uid = 'res-man-' . bin2hex(random_bytes(6));
        $doorCode = DoorCodeGenerator::generateRandom();

        $currentUser = $this->buildCurrentUser($session);
        $registryCompletedAt = $preMarkRegistry ? gmdate('Y-m-d H:i:s') : null;

        $reservationData = [
            'reservation_uid' => $uid,
            'property_id' => $propertyId,
            'guest_name' => $guestName,
            'guest_email' => $guestEmail,
            'guest_phone' => $guestPhone,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'total_price' => $totalPrice,
            'source' => $source,
            'notes' => $notes !== '' ? $notes : null,
            'door_code' => $doorCode,
            'registry_completed' => $preMarkRegistry ? 1 : 0,
            'registry_completed_at' => $registryCompletedAt,
            'status' => 'confirmed',
            'payment_status' => 'approved',
            'lang' => 'es',
        ];

        $this->repository->createManualReservation($reservationData);

        // Record audit trail
        $this->auditLogger->record(
            action: 'manual_reservation_created',
            entityType: 'reservation',
            entityId: $uid,
            before: null,
            after: $reservationData,
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        // Best-effort post-commit confirmation email delivery
        if ($sendConfirmationEmail) {
            $reservationEntity = new Reservation(
                reservationUid: $uid,
                propertyId: $propertyId,
                guestName: $guestName,
                guestEmail: $guestEmail,
                guestPhone: $guestPhone,
                checkIn: $checkIn,
                checkOut: $checkOut,
                totalPrice: $totalPrice,
                status: ReservationStatus::CONFIRMED,
                lang: 'es'
            );

            $this->sendConfirmationEmailSafely($reservationEntity, $currentUser, $request);
        }

        if ($request->isHtmx()) {
            return new Response(
                statusCode: 200,
                headers: [
                    'HX-Location' => '/reservations/' . urlencode($uid),
                    'HX-Trigger' => 'reservationUpdated',
                ],
                body: ''
            );
        }

        return Response::redirect('/reservations/' . urlencode($uid));
    }

    /**
     * Manually marks guest registry as verified and complete for out-of-band guests.
     *
     * @param array<string, mixed> $session
     */
    public function completeRegistry(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $reservation = $this->repository->findReservationByUid($uid);

        if ($reservation === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found</div>', 404);
        }

        $existingCode = $reservation['door_code'] ?? null;
        $doorCode = ($existingCode === null || $existingCode === '') ? DoorCodeGenerator::generateRandom() : null;

        $this->repository->updateRegistryCompleted($uid, $doorCode);

        $currentUser = $this->buildCurrentUser($session);
        $this->auditLogger->record(
            action: 'registry_manual_complete',
            entityType: 'reservation',
            entityId: $uid,
            before: [
                'registry_completed' => (int) $reservation['registry_completed'],
                'door_code' => $existingCode,
            ],
            after: [
                'registry_completed' => 1,
                'door_code' => $doorCode ?? $existingCode,
            ],
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        $data = $this->repository->findReservationWithAuditTrail($uid);
        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $data !== null ? $data['reservation'] : $reservation,
            'auditLogs' => $data !== null ? $data['audit_logs'] : [],
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
        ]);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/html; charset=UTF-8',
                'HX-Trigger' => 'reservationUpdated',
            ],
            body: $drawerHtml
        );
    }

    /**
     * Manually overrides the door code (PIN) for a confirmed reservation.
     *
     * @param array<string, mixed> $session
     */
    public function overrideDoorCode(Request $request, array &$session): Response
    {
        $body = $request->getAllPost();
        $rawCode = trim((string) ($body['door_code'] ?? ''));

        if (!preg_match('/^[0-9]{4,10}#?$/', $rawCode)) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Invalid PIN format. Code must be 4 to 10 digits optionally ending with #.</div>', 422);
        }

        $formattedCode = str_ends_with($rawCode, '#') ? $rawCode : $rawCode . '#';
        return $this->applyDoorCodeChange($request, $session, 'pin_override', $formattedCode);
    }

    /**
     * Regenerates a fresh random door code (PIN) for a confirmed reservation.
     *
     * @param array<string, mixed> $session
     */
    public function regenerateDoorCode(Request $request, array &$session): Response
    {
        return $this->applyDoorCodeChange($request, $session, 'pin_regenerate', DoorCodeGenerator::generateRandom());
    }

    /**
     * @param array<string, mixed> $session
     */
    private function applyDoorCodeChange(
        Request $request,
        array &$session,
        string $action,
        string $newCode
    ): Response {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $reservation = $this->repository->findReservationByUid($uid);

        if ($reservation === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found</div>', 404);
        }

        if (($reservation['status'] ?? '') !== 'confirmed') {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">PIN modification is strictly restricted to confirmed reservations.</div>', 422);
        }

        $this->repository->updateDoorCode($uid, $newCode);

        $currentUser = $this->buildCurrentUser($session);
        $this->auditLogger->record(
            action: $action,
            entityType: 'reservation',
            entityId: $uid,
            before: ['door_code' => $reservation['door_code'] ?? null],
            after: ['door_code' => $newCode],
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        $data = $this->repository->findReservationWithAuditTrail($uid);
        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $data !== null ? $data['reservation'] : $reservation,
            'auditLogs' => $data !== null ? $data['audit_logs'] : [],
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
        ]);

        return new Response(
            statusCode: 200,
            headers: [
                'Content-Type' => 'text/html; charset=UTF-8',
                'HX-Trigger' => 'reservationUpdated',
            ],
            body: $drawerHtml
        );
    }

    /**
     * @param array<string, mixed> $currentUser
     */
    private function sendConfirmationEmailSafely(
        Reservation $reservation,
        array $currentUser,
        Request $request
    ): void {
        try {
            $subject = $this->emailRenderer->renderGuestSubject($reservation);
            $htmlBody = $this->emailRenderer->renderGuestConfirmationHtml($reservation);
            $sent = $this->emailSender->send($reservation->guestEmail, $subject, $htmlBody);

            if (!$sent) {
                $this->logEmailFailure($reservation->reservationUid, 'Email sender returned false', $currentUser, $request);
            }
        } catch (Throwable $e) {
            $this->logEmailFailure($reservation->reservationUid, $e->getMessage(), $currentUser, $request);
        }
    }

    /**
     * @param array<string, mixed> $currentUser
     */
    private function logEmailFailure(string $uid, string $error, array $currentUser, Request $request): void
    {
        $this->auditLogger->record(
            action: 'email_delivery_failed',
            entityType: 'reservation',
            entityId: $uid,
            before: null,
            after: ['error' => $error],
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $filters
     */
    private function renderFullDashboard(
        array $session,
        array $filters = [],
        ?string $drawerHtml = null,
        ?string $modalHtml = null,
        string $title = 'Reservations - Ocean View Flats Admin'
    ): string {
        $searchResult = $this->repository->searchReservations($filters);
        $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
            'items' => $searchResult['items'],
            'total' => $searchResult['total'],
            'page' => $searchResult['page'],
            'per_page' => $searchResult['per_page'],
            'total_pages' => $searchResult['total_pages'],
            'filters' => $filters,
        ]);

        return $this->viewRenderer->render(
            template: 'reservations/index.php',
            data: [
                'title' => $title,
                'currentRoute' => '/reservations',
                'currentUser' => $this->buildCurrentUser($session),
                'csrfToken' => (string) ($session['csrf_token'] ?? ''),
                'tableHtml' => $tableHtml,
                'drawerHtml' => $drawerHtml,
                'modalHtml' => $modalHtml,
                'filters' => $filters,
            ]
        );
    }

    private function renderCreateError(Request $request, string $errorMessage): Response
    {
        $modalHtml = $this->viewRenderer->renderPartial('reservations/_create_modal.php', [
            'errorMessage' => $errorMessage,
        ]);

        return Response::html($modalHtml, 422);
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
