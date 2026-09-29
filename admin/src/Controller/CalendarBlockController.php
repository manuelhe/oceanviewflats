<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Controller;

use DateTimeImmutable;
use OceanViewFlats\Admin\Audit\AuditLogger;
use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Repository\AdminCalendarBlockRepository;
use OceanViewFlats\Admin\Views\ViewRenderer;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;

/**
 * Administrative controller for maintenance blocks and calendar holds.
 * Integrates with ReservationLedger to prevent collisions with active reservations
 * and enforces immutability on concluded historical holds per ADR 0006.
 */
final class CalendarBlockController
{
    public function __construct(
        private readonly AdminCalendarBlockRepository $blockRepository,
        private readonly ReservationLedgerInterface $ledger,
        private readonly ViewRenderer $viewRenderer,
        private readonly AuditLogger $auditLogger
    ) {
    }

    /**
     * GET /calendar-blocks: Lists maintenance holds filtered by property and status.
     *
     * @param array<string, mixed> $session
     */
    public function index(Request $request, array &$session): Response
    {
        $propertyId = $this->resolvePropertyId((string) $request->getQuery('property_id', 'all'));
        $filter = $this->resolveFilter((string) $request->getQuery('filter', 'upcoming'));

        $blocks = $this->blockRepository->getBlocks($propertyId, $filter);
        $csrfToken = (string) ($session['csrf_token'] ?? '');

        if ($request->isHtmx()) {
            $tableHtml = $this->viewRenderer->renderPartial('calendar_blocks/_table.php', [
                'blocks' => $blocks,
                'propertyId' => $propertyId,
                'filter' => $filter,
                'csrfToken' => $csrfToken,
            ]);

            return Response::html($tableHtml, 200);
        }

        $fullHtml = $this->viewRenderer->render('calendar_blocks/index.php', [
            'propertyId' => $propertyId,
            'filter' => $filter,
            'blocks' => $blocks,
            'csrfToken' => $csrfToken,
            'currentUser' => $this->buildCurrentUser($session),
            'currentRoute' => '/calendar-blocks',
        ]);

        return Response::html($fullHtml, 200);
    }

    /**
     * GET /calendar-blocks/new: Renders modal dialog for adding a new maintenance hold.
     *
     * @param array<string, mixed> $session
     */
    public function newHold(Request $request, array &$session): Response
    {
        $propertyId = $this->resolvePropertyId((string) $request->getQuery('property_id', '1606'));
        if ($propertyId === 'all') {
            $propertyId = '1606';
        }
        $filter = $this->resolveFilter((string) $request->getQuery('filter', 'upcoming'));

        $modalHtml = $this->viewRenderer->renderPartial('calendar_blocks/_modal_form.php', [
            'propertyId' => $propertyId,
            'startDate' => '',
            'endDate' => '',
            'reason' => '',
            'csrfToken' => (string) ($session['csrf_token'] ?? ''),
            'errors' => [],
            'infoMessage' => null,
            'filter' => $filter,
        ]);

        return Response::html($modalHtml, 200);
    }

    /**
     * POST /calendar-blocks: Validates and creates a new maintenance hold.
     * Rejects collisions with confirmed/pending direct reservations (HTTP 422).
     *
     * @param array<string, mixed> $session
     */
    public function create(Request $request, array &$session): Response
    {
        $propertyId = trim((string) $request->getPost('property_id', ''));
        $startDate = trim((string) $request->getPost('start_date', ''));
        $endDate = trim((string) $request->getPost('end_date', ''));
        $reason = trim((string) $request->getPost('reason', ''));
        $filter = $this->resolveFilter((string) $request->getPost('current_filter', 'upcoming'));
        $csrfToken = (string) ($session['csrf_token'] ?? '');

        // 1. Validate CSRF token
        $submittedToken = (string) $request->getPost('csrf_token', '');
        if (!hash_equals($csrfToken, $submittedToken)) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Invalid or expired CSRF token.</div>', 403);
        }

        // 2. Format & Range Validations
        $errors = [];
        if (!in_array($propertyId, ['1606', '1707'], true)) {
            $errors[] = 'Please select a valid property unit (1606 or 1707).';
        }
        if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = 'Start date must be a valid date in YYYY-MM-DD format.';
        }
        if ($endDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $errors[] = 'End date must be a valid date in YYYY-MM-DD format.';
        }
        if ($startDate !== '' && $endDate !== '' && $startDate >= $endDate) {
            $errors[] = sprintf('End date (%s) must be strictly after start date (%s).', $endDate, $startDate);
        }
        if ($reason === '') {
            $errors[] = 'Reason / operational justification cannot be empty.';
        }

        // 3. Collision Checks (if date format is valid)
        $infoMessage = null;
        if (empty($errors)) {
            // Check collisions with active direct reservations (Confirmed or active Pending)
            $resConflict = $this->ledger->findReservationConflict($propertyId, $startDate, $endDate);
            if ($resConflict !== null) {
                $errors[] = sprintf(
                    'Cannot create maintenance hold: Collides with active direct reservation %s for %s (%s to %s).',
                    $resConflict->reservationUid,
                    $resConflict->guestName,
                    $resConflict->checkIn,
                    $resConflict->checkOut
                );
            }

            // Check collision with existing maintenance blocks
            if ($this->blockRepository->hasOverlap($propertyId, $startDate, $endDate)) {
                $errors[] = 'Proposed dates overlap with an existing maintenance hold for this property.';
            }

            // Check if overlapping external OTA channel block (informational note, permitted per ADR 0006)
            $channelConflict = $this->ledger->findChannelConflict($propertyId, $startDate, $endDate);
            if ($channelConflict !== null) {
                $infoMessage = sprintf(
                    'Note: These dates overlap an external %s block. Local maintenance hold takes precedence.',
                    $channelConflict->source
                );
            }
        }

        // 4. Return Validation Errors (HTTP 422)
        if (!empty($errors)) {
            $modalHtml = $this->viewRenderer->renderPartial('calendar_blocks/_modal_form.php', [
                'propertyId' => $propertyId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'reason' => $reason,
                'csrfToken' => $csrfToken,
                'errors' => $errors,
                'infoMessage' => $infoMessage,
                'filter' => $filter,
            ]);

            return Response::html($modalHtml, 422);
        }

        // 5. Persist Maintenance Block
        $adminUserId = isset($session['admin_user_id']) ? (int) $session['admin_user_id'] : null;
        $adminEmail = (string) ($session['admin_email'] ?? 'admin@oceanviewflats.com');

        $blockId = $this->blockRepository->createBlock(
            propertyId: $propertyId,
            startDate: $startDate,
            endDate: $endDate,
            reason: $reason,
            createdBy: $adminUserId
        );

        // 6. Audit Trail Logging
        $this->auditLogger->record(
            action: 'calendar_block_created',
            entityType: 'calendar_block',
            entityId: (string) $blockId,
            before: null,
            after: [
                'property_id' => $propertyId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'reason' => $reason,
            ],
            adminUserId: $adminUserId,
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        // 7. Response: close modal and re-render blocks container
        $blocks = $this->blockRepository->getBlocks($propertyId, $filter);
        $tableHtml = $this->viewRenderer->renderPartial('calendar_blocks/_table.php', [
            'blocks' => $blocks,
            'propertyId' => $propertyId,
            'filter' => $filter,
            'csrfToken' => $csrfToken,
        ]);

        if ($request->isHtmx()) {
            $responseHtml = '<div id="blocks-container" hx-swap-oob="true">' . $tableHtml . '</div>';
            $responseHtml .= '<div id="modal-container"></div>';

            return new Response(
                statusCode: 200,
                headers: [
                    'Content-Type' => 'text/html; charset=UTF-8',
                    'HX-Trigger' => 'blockSaved',
                ],
                body: $responseHtml
            );
        }

        return Response::redirect('/calendar-blocks?property_id=' . urlencode($propertyId) . '&filter=' . urlencode($filter));
    }

    /**
     * DELETE /calendar-blocks/{id}: Deletes active/upcoming maintenance hold.
     * Rejects deletion of concluded historical holds (HTTP 422) per ADR 0006.
     *
     * @param array<string, mixed> $session
     */
    public function delete(Request $request, array &$session): Response
    {
        $id = (int) $request->getAttribute('id');
        $propertyId = $this->resolvePropertyId((string) $request->getQuery('property_id', (string) $request->getPost('property_id', 'all')));
        $filter = $this->resolveFilter((string) $request->getQuery('filter', (string) $request->getPost('filter', 'upcoming')));
        $csrfToken = (string) ($session['csrf_token'] ?? '');

        // 1. Verify CSRF for mutating requests
        $submittedToken = (string) ($request->getPost('csrf_token', $request->getHeader('X-CSRF-Token', '')));
        if ($csrfToken !== '' && !hash_equals($csrfToken, $submittedToken)) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Invalid CSRF token.</div>', 403);
        }

        // 2. Fetch existing block
        $block = $this->blockRepository->findById($id);
        if ($block === null) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Maintenance hold not found.</div>', 404);
        }

        // 3. Immutability Invariant: Concluded historical blocks cannot be deleted (ADR 0006)
        $today = date('Y-m-d');
        if ((string) $block['end_date'] <= $today) {
            return Response::html('<div class="p-4 text-xs text-rose-600 font-semibold">Concluded historical maintenance blocks cannot be deleted.</div>', 422);
        }

        // 4. Delete block
        $this->blockRepository->deleteBlock($id);

        // 5. Audit Trail Logging
        $adminUserId = isset($session['admin_user_id']) ? (int) $session['admin_user_id'] : null;

        $this->auditLogger->record(
            action: 'calendar_block_deleted',
            entityType: 'calendar_block',
            entityId: (string) $id,
            before: $block,
            after: null,
            adminUserId: $adminUserId,
            ipAddress: $request->getClientIp(),
            userAgent: (string) $request->getHeader('User-Agent', '')
        );

        // 6. Response
        $blocks = $this->blockRepository->getBlocks($propertyId, $filter);
        $tableHtml = $this->viewRenderer->renderPartial('calendar_blocks/_table.php', [
            'blocks' => $blocks,
            'propertyId' => $propertyId,
            'filter' => $filter,
            'csrfToken' => $csrfToken,
        ]);

        if ($request->isHtmx()) {
            return Response::html($tableHtml, 200);
        }

        return Response::redirect('/calendar-blocks?property_id=' . urlencode($propertyId) . '&filter=' . urlencode($filter));
    }

    private function resolvePropertyId(string $input): string
    {
        return in_array($input, ['1606', '1707'], true) ? $input : 'all';
    }

    private function resolveFilter(string $input): string
    {
        return in_array($input, ['upcoming', 'past', 'all'], true) ? $input : 'upcoming';
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|null
     */
    private function buildCurrentUser(array $session): ?array
    {
        if (empty($session['admin_user_id'])) {
            return null;
        }

        return [
            'id' => $session['admin_user_id'],
            'name' => $session['admin_user_name'] ?? 'Admin User',
            'email' => $session['admin_email'] ?? '',
            'role' => $session['admin_role'] ?? 'admin',
        ];
    }
}
