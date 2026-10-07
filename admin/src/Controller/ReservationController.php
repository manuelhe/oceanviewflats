<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use DateTimeImmutable;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Service\MercadoPagoRefundClientInterface;
use OceanViewFlats\Admin\Service\MercadoPagoRefundException;
use OceanViewFlats\Admin\Service\PublicUrlBuilder;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Access\DoorCodeGenerator;
use OceanViewFlats\Domain\Fulfillment\AdminContext;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearance;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearanceRepositoryInterface;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearanceSyncInterface;
use OceanViewFlats\Domain\Fulfillment\EmailSenderInterface;
use OceanViewFlats\Domain\Fulfillment\GuestLifecycleFulfillmentServiceInterface;
use OceanViewFlats\Domain\Fulfillment\HuespedManagerClearanceSync;
use OceanViewFlats\Domain\Fulfillment\PdoCondominiumClearanceRepository;
use OceanViewFlats\Domain\Quote\QuoteEngineInterface;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use OceanViewFlats\Domain\Reservation\Search\ReservationSearchCriteria;
use OceanViewFlats\Domain\Reservation\Search\ReservationSearchInterface;
use OceanViewFlats\Domain\Reservation\Search\ReservationSearchResult;
use PDO;
use Throwable;

/**
 * Controller handling reservation management, real-time debounced filtering,
 * slide-over detail inspector drawer, manual booking creation, PIN overrides,
 * cancellation with automated refunds, and guest registry administration.
 */
final class ReservationController
{
    private readonly CancellationEmailRendererInterface $cancellationEmailRenderer;
    private readonly PublicUrlBuilder $urlBuilder;
    private readonly ?CondominiumClearanceRepositoryInterface $clearanceRepo;
    private readonly ?CondominiumClearanceSyncInterface $clearanceSync;

    public function __construct(
        private readonly ReservationRepositoryInterface $repository,
        private readonly ReservationSearchInterface $search,
        private readonly ViewRenderer $viewRenderer,
        private readonly AuditLogger $auditLogger,
        private readonly ReservationLedgerInterface $ledger,
        private readonly QuoteEngineInterface $quoteEngine,
        private readonly EmailSenderInterface $emailSender,
        private readonly GuestLifecycleFulfillmentServiceInterface $lifecycleService,
        private readonly string $publicSiteUrl = 'https://oceanviewflats.com',
        private readonly ?MercadoPagoRefundClientInterface $refundClient = null,
        ?CancellationEmailRendererInterface $cancellationEmailRenderer = null,
        private readonly ?PDO $pdo = null,
        ?PublicUrlBuilder $urlBuilder = null,
        ?CondominiumClearanceRepositoryInterface $clearanceRepo = null,
        ?CondominiumClearanceSyncInterface $clearanceSync = null
    ) {
        $this->cancellationEmailRenderer = $cancellationEmailRenderer ?? new CancellationEmailRenderer($this->publicSiteUrl);
        $this->urlBuilder = $urlBuilder ?? new PublicUrlBuilder($this->publicSiteUrl);
        $this->clearanceRepo = $clearanceRepo ?? ($this->pdo !== null ? new PdoCondominiumClearanceRepository($this->pdo) : null);
        $this->clearanceSync = $clearanceSync ?? ($this->clearanceRepo !== null ? new HuespedManagerClearanceSync($this->clearanceRepo) : null);
    }

    /**
     * Lists reservations with pagination, live debounced filtering, and sorting.
     *
     * @param array<string, mixed> $session
     */
    public function list(Request $request, array &$session): Response
    {
        $filters = $request->getAllQuery();
        $criteria = ReservationSearchCriteria::fromArray($filters);
        $searchResult = $this->search->search($criteria);

        if ($request->isHtmx()) {
            $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
                'items' => $searchResult->items,
                'total' => $searchResult->totalCount,
                'page' => $searchResult->page,
                'per_page' => $searchResult->limit,
                'total_pages' => $searchResult->totalPages,
                'filters' => $filters,
            ]);
            return Response::html($tableHtml);
        }

        return Response::html($this->renderFullDashboard($session, $filters, $searchResult));
    }

    /**
     * Inspects a single reservation via slide-over drawer or full index view.
     *
     * @param array<string, mixed> $session
     */
    public function show(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $dossier = $this->search->findWithAuditTrail($uid);

        if ($dossier === null) {
            if ($request->isHtmx()) {
                $errorBanner = '
                    <div class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-xs flex items-center justify-center p-4">
                        <div class="bg-white rounded-xl shadow-xl p-6 max-w-sm text-center border border-gray-200">
                            <p class="text-sm font-semibold text-rose-600 mb-2">Reservation not found</p>
                            <p class="text-xs text-gray-500 mb-4">No reservation exists with UID: ' . htmlspecialchars($uid, ENT_QUOTES, 'UTF-8') . '</p>
                            <button type="button" onclick="closeReservationDrawer();" class="px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-xs rounded-md text-gray-700 font-medium cursor-pointer">Dismiss</button>
                        </div>
                    </div>
                ';
                return Response::html($errorBanner, 404);
            }

            return Response::redirect('/reservations');
        }

        $clearance = $this->clearanceRepo?->findByReservationUid($uid);

        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $dossier->reservation,
            'auditLogs' => $dossier->auditLogs,
            'refunds' => $dossier->refunds,
            'condominiumClearance' => $clearance,
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
            'urlBuilder' => $this->urlBuilder,
        ]);

        if ($request->isHtmx() && $request->getHeader('HX-Target') === 'drawer-container') {
            return Response::html($drawerHtml);
        }

        // Direct browser request or non-drawer HTMX request: render full dashboard with pre-opened drawer
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
        $reservation = $this->repository->findByUid($uid);

        if ($reservation === null) {
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

        $registry = $this->search->findGuestRegistry($uid);

        $modalHtml = $this->viewRenderer->renderPartial('reservations/_registry_modal.php', [
            'reservation' => $reservation->toArray(),
            'registry' => $registry,
            'publicSiteUrl' => $this->publicSiteUrl,
            'urlBuilder' => $this->urlBuilder,
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
        $propertyId = (string) $request->getQuery('property_id', '');
        $checkIn = (string) $request->getQuery('check_in', '');
        $checkOut = (string) $request->getQuery('check_out', '');
        $source = (string) $request->getQuery('source', 'manual_override');
        $channelBlockUid = (string) $request->getQuery('channel_block_uid', '');
        $externalConfirmationCode = (string) $request->getQuery('external_confirmation_code', '');

        $modalHtml = $this->viewRenderer->renderPartial('reservations/_create_modal.php', [
            'errorMessage' => null,
            'propertyId' => $propertyId,
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'source' => $source,
            'channelBlockUid' => $channelBlockUid,
            'externalConfirmationCode' => $externalConfirmationCode,
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

        $absorbingSource = (strtolower($source) === 'airbnb') ? 'airbnb' : null;
        $isAvailable = $this->ledger->isAvailable($propertyId, $checkIn, $checkOut, null, $absorbingSource);
        $conflictReasons = $isAvailable ? [] : $this->ledger->getConflictReasons($propertyId, $checkIn, $checkOut, null, $absorbingSource);
        $quote = null;
        $defaultPrice = 0.0;

        if ($isAvailable) {
            try {
                $quote = $this->quoteEngine->quote($propertyId, $checkIn, $checkOut);
                $defaultPrice = ($source === 'owner_stay' || strtolower($source) === 'airbnb') ? 0.0 : $quote->totalCop;
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
        if ($source === '') {
            $source = 'manual';
        }
        $totalPriceRaw = $body['total_price'] ?? null;
        $guestName = trim((string) ($body['guest_name'] ?? ''));
        $guestEmail = trim((string) ($body['guest_email'] ?? ''));
        $guestPhone = trim((string) ($body['guest_phone'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        $preMarkRegistry = isset($body['pre_mark_registry']) && (string) $body['pre_mark_registry'] === '1';
        $sendConfirmationEmail = isset($body['send_confirmation_email']) && (string) $body['send_confirmation_email'] === '1';
        $externalConfirmationCode = trim((string) ($body['external_confirmation_code'] ?? ''));
        $channelBlockUid = trim((string) ($body['channel_block_uid'] ?? '')) ?: null;

        $isAirbnb = (strtolower($source) === 'airbnb');

        if ($isAirbnb) {
            $airbnbResult = $this->normalizeAirbnbInputs($externalConfirmationCode, $totalPriceRaw, $guestEmail, $guestPhone, $sendConfirmationEmail);
            if (is_string($airbnbResult)) {
                return $this->renderCreateError($request, $airbnbResult);
            }
            [$guestEmail, $guestPhone, $totalPrice] = $airbnbResult;
        } else {
            $totalPrice = is_numeric($totalPriceRaw) ? (float) $totalPriceRaw : -1.0;
        }

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

        // Ledger conflict check (absorbs matching external channel block if source is airbnb per ADR 0007)
        $absorbingSource = $isAirbnb ? 'airbnb' : null;
        if (!$this->ledger->isAvailable($propertyId, $checkIn, $checkOut, null, $absorbingSource)) {
            return $this->renderCreateError($request, 'Selected dates conflict with an existing reservation or channel block.');
        }

        // Generate UID and random Door Code
        $uid = $isAirbnb ? ('res-abnb-' . bin2hex(random_bytes(4))) : ('res-man-' . bin2hex(random_bytes(6)));
        $doorCode = DoorCodeGenerator::generateRandom();

        $currentUser = $this->buildCurrentUser($session);
        $registryCompletedAt = $preMarkRegistry ? new DateTimeImmutable() : null;

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
            paymentMethodId: $isAirbnb ? 'external_ota' : 'manual',
            paymentStatus: 'approved',
            lang: 'es',
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
            registryCompleted: $preMarkRegistry,
            registryCompletedAt: $registryCompletedAt,
            doorCode: $doorCode,
            source: $source,
            externalConfirmationCode: $isAirbnb ? $externalConfirmationCode : null,
            channelBlockUid: $isAirbnb ? $channelBlockUid : null,
            notes: $notes !== '' ? $notes : null
        );

        $savedReservation = $this->repository->save($reservationEntity);

        // Record audit trail
        $this->auditLogger->record(
            action: $isAirbnb ? 'airbnb_reservation_created' : 'manual_reservation_created',
            entityType: 'reservation',
            entityId: $uid,
            before: null,
            after: $savedReservation->toArray(),
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        // Best-effort post-commit confirmation email delivery
        if ($sendConfirmationEmail) {
            $this->lifecycleService->fulfillBookingConfirmation($savedReservation);
        }

        $dossier = $this->search->findWithAuditTrail($uid);
        $clearance = $this->clearanceRepo?->findByReservationUid($uid);
        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $dossier !== null ? $dossier->reservation : $savedReservation->toArray(),
            'auditLogs' => $dossier !== null ? $dossier->auditLogs : [],
            'refunds' => $dossier !== null ? $dossier->refunds : [],
            'condominiumClearance' => $clearance,
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
            'urlBuilder' => $this->urlBuilder,
        ]);

        if ($request->isHtmx()) {
            $responseBody = '<script>document.getElementById("modal-container").innerHTML = "";</script>';
            $responseBody .= '<div id="drawer-container" hx-swap-oob="innerHTML">' . $drawerHtml . '</div>';

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'HX-Trigger' => 'reservationUpdated',
                    'HX-Push-Url' => '/reservations/' . urlencode($uid),
                ],
                body: $responseBody
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
        $reservation = $this->repository->findByUid($uid);

        if ($reservation === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found</div>', 404);
        }

        $currentUser = $this->buildCurrentUser($session);
        $adminContext = new AdminContext(
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        $result = $this->lifecycleService->completeRegistryManually($uid, $adminContext);
        if (!$result->success) {
            $errorMessage = !empty($result->errors) ? implode(', ', $result->errors) : 'Failed to complete registry.';
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') . '</div>', 400);
        }

        return $this->renderDetailDrawerResponse($uid, $session, $reservation->toArray());
    }

    /**
     * Retries or initiates condominium clearance synchronization via the Condominium Administration Portal.
     *
     * @param array<string, mixed> $session
     */
    public function retryClearance(Request $request, array &$session): Response
    {
        // 1. Session authorization check
        $currentUser = $this->buildCurrentUser($session);
        if ($currentUser['id'] === null) {
            if ($request->isHtmx()) {
                return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Unauthorized: Administrative session required.</div>', 401);
            }
            return Response::json(['success' => false, 'error' => 'Unauthorized: Administrative session required.'], 401);
        }

        // 2. CSRF validation for mutating requests
        if ($request->isMutating()) {
            $sessionCsrf = (string) ($session['csrf_token'] ?? '');
            $clientCsrf = (string) (
                $request->getHeader('HX-CSRF-TOKEN')
                ?? $request->getHeader('X-CSRF-TOKEN')
                ?? $request->getPost('csrf_token')
                ?? ''
            );
            if ($sessionCsrf === '' || $clientCsrf === '' || !hash_equals($sessionCsrf, $clientCsrf)) {
                if ($request->isHtmx()) {
                    return Response::forbidden('<div class="p-4 text-xs text-rose-600 font-semibold">Forbidden: Invalid CSRF token.</div>', isHtmx: true);
                }
                return Response::json(['success' => false, 'error' => 'Forbidden: Invalid CSRF token.'], 403);
            }
        }

        $uid = (string) ($request->getAttribute('uid') ?? '');
        $reservation = $this->repository->findByUid($uid);

        if ($reservation === null) {
            if ($request->isHtmx()) {
                return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found.</div>', 404);
            }
            return Response::json(['success' => false, 'error' => 'Reservation not found.'], 404);
        }

        $registry = $this->search->findGuestRegistry($uid);
        if ($registry === null) {
            if ($request->isHtmx()) {
                return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Guest registry must be submitted before condominium clearance can be synced.</div>', 400);
            }
            return Response::json(['success' => false, 'error' => 'Guest registry must be submitted before condominium clearance can be synced.'], 400);
        }

        if ($this->clearanceSync === null) {
            if ($request->isHtmx()) {
                return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Condominium clearance synchronization service is unavailable.</div>', 500);
            }
            return Response::json(['success' => false, 'error' => 'Condominium clearance synchronization service is unavailable.'], 500);
        }

        $guests = $registry['guests_payload'] ?? [];
        if (is_string($guests)) {
            $decoded = json_decode($guests, true);
            $guests = is_array($decoded) ? $decoded : [];
        }

        $notes = HuespedManagerClearanceSync::formatClearanceNotes($reservation->notes, $registry['car_model'] ?? null);
        $carPlates = $registry['car_plates'] ?? null;

        $existingClearance = $this->clearanceRepo?->findByReservationUid($uid);
        $payloadBefore = $existingClearance?->toArray();

        $clearance = $this->clearanceSync->sync(
            reservationUid: $reservation->reservationUid,
            propertyId: $reservation->propertyId,
            checkIn: $reservation->checkIn,
            checkOut: $reservation->checkOut,
            guests: $guests,
            carPlates: $carPlates,
            notes: $notes
        );

        $this->auditLogger->record(
            action: 'condominium_clearance_retry',
            entityType: 'reservation',
            entityId: $uid,
            before: $payloadBefore,
            after: $clearance->toArray(),
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        if ($request->isHtmx()) {
            return $this->renderDetailDrawerResponse($uid, $session, $reservation->toArray(), $clearance);
        }

        return Response::json([
            'success' => $clearance->isSynced(),
            'status' => $clearance->status,
            'clearance_number' => $clearance->clearanceNumber,
            'error' => $clearance->errorMessage,
            'attempts' => $clearance->attempts,
            'last_attempt_at' => $clearance->lastAttemptAt,
            'synced_at' => $clearance->syncedAt,
        ]);
    }

    /**
     * Manually overrides the door code (PIN) for a confirmed reservation.
     *
     * @param array<string, mixed> $session
     */
    public function overrideDoorCode(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $body = $request->getAllPost();
        $rawCode = (string) ($body['door_code'] ?? '');

        $currentUser = $this->buildCurrentUser($session);
        $adminContext = new AdminContext(
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        $result = $this->lifecycleService->overrideDoorCode($uid, $rawCode, $adminContext);
        if (!$result->success) {
            $error = $result->error ?? 'An error occurred while updating the PIN.';
            $statusCode = ($error === 'Reservation not found') ? 404 : 422;
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>', $statusCode);
        }

        return $this->renderDetailDrawerResponse($uid, $session);
    }

    /**
     * Regenerates a fresh random door code (PIN) for a confirmed reservation.
     *
     * @param array<string, mixed> $session
     */
    public function regenerateDoorCode(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $currentUser = $this->buildCurrentUser($session);
        $adminContext = new AdminContext(
            adminUserId: $currentUser['id'],
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        $result = $this->lifecycleService->regenerateDoorCode($uid, $adminContext);
        if (!$result->success) {
            $error = $result->error ?? 'An error occurred while regenerating the PIN.';
            $statusCode = ($error === 'Reservation not found') ? 404 : 422;
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>', $statusCode);
        }

        return $this->renderDetailDrawerResponse($uid, $session);
    }

    /**
     * Helper to render detail drawer HTMX response.
     *
     * @param array<string, mixed> $session
     * @param array<string, mixed> $fallbackReservation
     */
    private function renderDetailDrawerResponse(
        string $uid,
        array &$session,
        array $fallbackReservation = [],
        ?CondominiumClearance $clearance = null
    ): Response {
        $dossier = $this->search->findWithAuditTrail($uid);
        $clearance ??= $this->clearanceRepo?->findByReservationUid($uid);
        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $dossier !== null ? $dossier->reservation : $fallbackReservation,
            'auditLogs' => $dossier !== null ? $dossier->auditLogs : [],
            'refunds' => $dossier !== null ? $dossier->refunds : [],
            'condominiumClearance' => $clearance,
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
            'urlBuilder' => $this->urlBuilder,
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
    private function sendCancellationEmailSafely(
        Reservation $reservation,
        float $refundAmount,
        float $policyRetention,
        array $currentUser,
        Request $request
    ): void {
        try {
            $subject = $this->cancellationEmailRenderer->renderGuestSubject($reservation);
            $htmlBody = $this->cancellationEmailRenderer->renderGuestCancellationHtml(
                reservation: $reservation,
                refundAmount: $refundAmount,
                policyRetention: $policyRetention
            );
            $sent = $this->emailSender->send($reservation->guestEmail, $subject, $htmlBody);

            if ($sent) {
                $this->auditLogger->record(
                    action: 'cancellation_email_sent',
                    entityType: 'reservation',
                    entityId: $reservation->reservationUid,
                    before: null,
                    after: [
                        'recipient' => $reservation->guestEmail,
                        'refund_amount' => $refundAmount,
                        'policy_retention' => $policyRetention,
                    ],
                    adminUserId: $currentUser['id'],
                    ipAddress: $request->getClientIp(),
                    userAgent: (string) $request->getHeader('User-Agent', '')
                );
            } else {
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
        ?ReservationSearchResult $searchResult = null,
        ?string $drawerHtml = null,
        ?string $modalHtml = null,
        string $title = 'Reservations - Ocean View Flats Admin'
    ): string {
        $result = $searchResult ?? $this->search->search(ReservationSearchCriteria::fromArray($filters));
        $tableHtml = $this->viewRenderer->renderPartial('reservations/_table.php', [
            'items' => $result->items,
            'total' => $result->totalCount,
            'page' => $result->page,
            'per_page' => $result->limit,
            'total_pages' => $result->totalPages,
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

    /**
     * Renders the cancellation confirmation and refund modal.
     *
     * @param array<string, mixed> $session
     */
    public function cancelModal(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');
        $reservation = $this->repository->findByUid($uid);

        if ($reservation === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found</div>', 404);
        }

        if ($reservation->status === ReservationStatus::CANCELLED) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation is already cancelled.</div>', 422);
        }

        $totalPrice = $reservation->totalPrice;
        $refundedAmount = $reservation->refundedAmount;
        $refundableBalance = max(0.0, round($totalPrice - $refundedAmount, 2));
        $isOnlinePayment = !empty($reservation->mercadopagoPaymentId);

        $modalHtml = $this->viewRenderer->renderPartial('reservations/_cancel_modal.php', [
            'reservation' => $reservation->toArray(),
            'refundableBalance' => $refundableBalance,
            'isOnlinePayment' => $isOnlinePayment,
            'errorMessage' => null,
            'oldInput' => [],
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
        ]);

        return Response::html($modalHtml);
    }

    /**
     * Executes atomic cancellation and Mercado Pago refund dispatch.
     *
     * @param array<string, mixed> $session
     */
    public function cancel(Request $request, array &$session): Response
    {
        $uid = (string) ($request->getAttribute('uid') ?? '');

        // 1. Verify CSRF Token
        $csrfToken = (string) $request->getPost('csrf_token', '');
        if (!hash_equals((string) ($session['csrf_token'] ?? ''), $csrfToken)) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Invalid or expired CSRF token. Please refresh.</div>', 403);
        }

        // 2. Acquire reservation
        $reservation = $this->repository->findByUid($uid);
        if ($reservation === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation not found</div>', 404);
        }

        if ($reservation->status === ReservationStatus::CANCELLED) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Reservation is already cancelled.</div>', 422);
        }

        $totalPrice = $reservation->totalPrice;
        $currentRefunded = $reservation->refundedAmount;
        $refundableBalance = max(0.0, round($totalPrice - $currentRefunded, 2));
        $isOnlinePayment = !empty($reservation->mercadopagoPaymentId);

        $reason = trim((string) $request->getPost('reason', ''));
        $refundType = trim((string) $request->getPost('refund_type', 'none'));
        $refundAmountInput = (float) $request->getPost('refund_amount', 0.0);
        $sendCancellationEmailRaw = $request->getPost('send_cancellation_email');

        // Helper for consistent validation/gateway error rendering
        $failWithCancelError = fn(string $errorMessage): Response => $this->renderCancelError(
            reservation: $reservation->toArray(),
            refundableBalance: $refundableBalance,
            isOnlinePayment: $isOnlinePayment,
            errorMessage: $errorMessage,
            oldInput: [
                'reason' => $reason,
                'refund_type' => $refundType,
                'refund_amount' => $refundAmountInput,
                'send_cancellation_email' => $sendCancellationEmailRaw,
            ],
            csrfToken: (string) ($session['csrf_token'] ?? '')
        );

        // 3. Validate Reason
        if ($reason === '') {
            return $failWithCancelError('Cancellation reason is required.');
        }

        // 4. Determine and validate Refund Amount
        if ($refundType === 'full') {
            $refundAmount = $refundableBalance;
        } elseif ($refundType === 'partial') {
            if ($refundAmountInput <= 0 || $refundAmountInput > $refundableBalance) {
                return $failWithCancelError('Partial refund amount must be greater than 0 and cannot exceed the refundable balance ($' . number_format($refundableBalance, 0, '.', ',') . ' COP).');
            }
            $refundAmount = round($refundAmountInput, 2);
        } else {
            $refundType = 'none';
            $refundAmount = 0.0;
        }

        // 5. External Gateway Refund Dispatch (if online payment & refund requested)
        // Must execute BEFORE opening the database transaction so network latency does not hold locks.
        $mpPaymentId = $isOnlinePayment ? $reservation->mercadopagoPaymentId : null;
        $mpRefundId = null;
        $source = $isOnlinePayment ? 'admin_pms' : 'admin_manual';

        if ($isOnlinePayment && $refundAmount > 0) {
            if ($this->refundClient === null) {
                return $failWithCancelError('Refund client service is unavailable. Please contact technical support.');
            }

            $idempotencyKey = 'ref_' . $uid . '_' . (int) $refundAmount . '_' . time();

            try {
                $refundResult = $this->refundClient->refundPayment((string) $mpPaymentId, $refundAmount, $idempotencyKey);
                $mpRefundId = (string) $refundResult['id'];
            } catch (MercadoPagoRefundException $e) {
                return $failWithCancelError($e->getUserFriendlyMessage());
            } catch (Throwable $e) {
                return $failWithCancelError('Gateway connection failed: ' . $e->getMessage());
            }
        }

        // 6. Begin database transaction for local mutations
        $pdo = $this->pdo;
        $inTransaction = false;
        if ($pdo !== null && !$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $inTransaction = true;
        }

        try {
            $currentUser = $this->buildCurrentUser($session);
            $adminUserId = $currentUser['id'];

            $cancelNote = '[Cancelled ' . date('Y-m-d H:i') . '] ' . $reason;
            if ($refundAmount > 0) {
                $cancelNote .= ' (Refund: COP ' . number_format($refundAmount, 2) . ', type: ' . $refundType . ')';
            } else {
                $cancelNote .= ' (Policy retention: No refund)';
            }
            $existingNotes = $reservation->notes !== null ? trim($reservation->notes) : '';
            $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote;

            $newRefundedAmount = round($currentRefunded + $refundAmount, 2);
            $newPaymentStatus = $reservation->paymentStatus ?? 'pending_payment';
            if ($newRefundedAmount >= $totalPrice && $totalPrice > 0) {
                $newPaymentStatus = 'refunded';
            } elseif ($newRefundedAmount > 0) {
                $newPaymentStatus = 'partially_refunded';
            }

            $cancelledReservation = $reservation->withRefund(
                additionalRefundAmount: $refundAmount,
                notes: $updatedNotes,
                status: ReservationStatus::CANCELLED,
                paymentStatus: $newPaymentStatus
            );

            $savedReservation = $this->repository->save($cancelledReservation);

            // Record refund in repository ledger
            if ($refundAmount > 0) {
                $this->repository->recordRefund([
                    'reservation_uid' => $uid,
                    'mercadopago_refund_id' => $mpRefundId,
                    'mercadopago_payment_id' => $mpPaymentId ?? 'offline',
                    'amount' => $refundAmount,
                    'status' => 'approved',
                    'reason' => $reason,
                    'source' => $source,
                    'admin_user_id' => $adminUserId,
                ]);
            }

            // Record audit logs
            $this->auditLogger->record(
                action: 'reservation_cancelled',
                entityType: 'reservation',
                entityId: $uid,
                before: [
                    'status' => $reservation->status->value,
                    'payment_status' => $reservation->paymentStatus,
                ],
                after: [
                    'status' => 'cancelled',
                    'reason' => $reason,
                    'refund_type' => $refundType,
                    'refund_amount' => $refundAmount,
                ],
                adminUserId: $adminUserId,
                ipAddress: $request->getClientIp(),
                userAgent: (string) $request->getHeader('User-Agent', '')
            );

            if ($refundAmount > 0) {
                $this->auditLogger->record(
                    action: 'refund_issued',
                    entityType: 'reservation',
                    entityId: $uid,
                    before: ['refunded_amount' => $currentRefunded],
                    after: [
                        'refunded_amount' => round($currentRefunded + $refundAmount, 2),
                        'refund_amount' => $refundAmount,
                        'mercadopago_refund_id' => $mpRefundId,
                        'refund_type' => $refundType,
                        'source' => $source,
                    ],
                    adminUserId: $adminUserId,
                    ipAddress: $request->getClientIp(),
                    userAgent: (string) $request->getHeader('User-Agent', '')
                );
            }

            // Commit transaction
            if ($inTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($inTransaction) {
                $pdo->rollBack();
            }
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Cancellation failed unexpectedly: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>', 500);
        }

        // 10. Resilient Post-Commit Guest Cancellation Email Dispatch
        $sendCancellationEmail = !empty($sendCancellationEmailRaw);
        if ($sendCancellationEmail) {
            $this->sendCancellationEmailSafely(
                reservation: $savedReservation,
                refundAmount: $refundAmount,
                policyRetention: max(0.0, round($totalPrice - ($currentRefunded + $refundAmount), 2)),
                currentUser: $currentUser,
                request: $request
            );
        }

        // 11. Render response
        $updatedDossier = $this->search->findWithAuditTrail($uid);
        $clearance = $this->clearanceRepo?->findByReservationUid($uid);
        $drawerHtml = $this->viewRenderer->renderPartial('reservations/_detail_drawer.php', [
            'reservation' => $updatedDossier !== null ? $updatedDossier->reservation : $savedReservation->toArray(),
            'auditLogs' => $updatedDossier !== null ? $updatedDossier->auditLogs : [],
            'refunds' => $updatedDossier !== null ? $updatedDossier->refunds : [],
            'condominiumClearance' => $clearance,
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'publicSiteUrl' => $this->publicSiteUrl,
            'urlBuilder' => $this->urlBuilder,
        ]);

        if ($request->isHtmx()) {
            $responseBody = '<script>document.getElementById("modal-container").innerHTML = "";</script>';
            $responseBody .= '<div id="drawer-container" hx-swap-oob="innerHTML">' . $drawerHtml . '</div>';

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'HX-Trigger' => 'reservationUpdated',
                ],
                body: $responseBody
            );
        }

        return Response::redirect('/reservations/' . urlencode($uid));
    }

    /**
     * @param array<string, mixed> $reservation
     * @param array<string, mixed> $oldInput
     */
    private function renderCancelError(
        array $reservation,
        float $refundableBalance,
        bool $isOnlinePayment,
        string $errorMessage,
        array $oldInput,
        string $csrfToken
    ): Response {
        $modalHtml = $this->viewRenderer->renderPartial('reservations/_cancel_modal.php', [
            'reservation' => $reservation,
            'refundableBalance' => $refundableBalance,
            'isOnlinePayment' => $isOnlinePayment,
            'errorMessage' => $errorMessage,
            'oldInput' => $oldInput,
            'csrfToken' => $csrfToken,
        ]);

        return Response::html($modalHtml, 422);
    }

    /**
     * @return array{0: string, 1: string, 2: float}|string Normalized [guestEmail, guestPhone, totalPrice] or error string
     */
    private function normalizeAirbnbInputs(
        string $externalConfirmationCode,
        mixed $totalPriceRaw,
        string $guestEmail,
        string $guestPhone,
        bool $sendConfirmationEmail
    ): array|string {
        if ($externalConfirmationCode === '') {
            return 'Airbnb confirmation code is required.';
        }

        if ($totalPriceRaw === null || trim((string) $totalPriceRaw) === '') {
            $totalPrice = 0.0;
        } else {
            $totalPrice = is_numeric($totalPriceRaw) ? (float) $totalPriceRaw : -1.0;
        }

        if ($guestEmail === '' && !$sendConfirmationEmail) {
            $cleanCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $externalConfirmationCode) ?? 'guest');
            $guestEmail = 'airbnb-' . $cleanCode . '@guest.oceanviewflats.com';
        }

        if ($guestPhone === '') {
            $guestPhone = 'N/A';
        }

        return [$guestEmail, $guestPhone, $totalPrice];
    }

    private function renderCreateError(Request $request, string $errorMessage): Response
    {
        $body = $request->getAllPost();
        $modalHtml = $this->viewRenderer->renderPartial('reservations/_create_modal.php', [
            'errorMessage' => $errorMessage,
            'propertyId' => (string) ($body['property_id'] ?? ''),
            'checkIn' => (string) ($body['check_in'] ?? ''),
            'checkOut' => (string) ($body['check_out'] ?? ''),
            'source' => (string) ($body['source'] ?? 'manual_override'),
            'channelBlockUid' => (string) ($body['channel_block_uid'] ?? ''),
            'externalConfirmationCode' => (string) ($body['external_confirmation_code'] ?? ''),
            'guestName' => (string) ($body['guest_name'] ?? ''),
            'guestEmail' => (string) ($body['guest_email'] ?? ''),
            'guestPhone' => (string) ($body['guest_phone'] ?? ''),
            'totalPrice' => (string) ($body['total_price'] ?? ''),
            'notes' => (string) ($body['notes'] ?? ''),
            'preMarkRegistry' => isset($body['pre_mark_registry']),
            'sendConfirmationEmail' => isset($body['send_confirmation_email']),
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
