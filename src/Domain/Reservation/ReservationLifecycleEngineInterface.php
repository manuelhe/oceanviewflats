<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Authoritative Reservation Lifecycle Engine domain contract.
 * Encapsulates all lifecycle state invariants, calendar availability,
 * quote computation, channel block absorption, and refund orchestration.
 */
interface ReservationLifecycleEngineInterface
{
    /**
     * Places a temporary concurrency-safe hold for direct web checkout.
     *
     * @throws ReservationConflictException If requested dates are blocked or held.
     * @throws ReservationValidationException If dates or minimum stay requirements fail.
     */
    public function holdDirect(DirectHoldRequest $request): DirectHoldResult;

    /**
     * Inception seam: confirms direct checkout or records manual/external reservations.
     * Enforces calendar availability, seasonal minimum stay, channel block absorption,
     * authoritative Quote calculation (COP), and access credential allocation.
     *
     * @throws ReservationConflictException If requested dates conflict.
     * @throws ReservationValidationException If draft validation or minimum stay fails.
     * @throws InvalidReservationStateException If state transition is invalid.
     */
    public function confirmOrRecord(ReservationDraft $draft): ReservationResult;

    /**
     * Side-effect-free dry-run calculating refundable balance, elapsed days, and
     * suggested policy retention for administrative cancellation interfaces.
     *
     * @throws InvalidReservationStateException If reservation is already cancelled or concluded.
     * @throws \InvalidArgumentException If reservation does not exist.
     */
    public function previewCancellation(string $reservationUid): CancellationPreview;

    /**
     * Voids an active reservation, coordinates pre-transaction gateway refunds,
     * updates database state, records audit log, and delivers cancellation notices.
     *
     * @throws InvalidReservationStateException If reservation is already cancelled or concluded.
     * @throws ExcessiveRefundException If refund exceeds refundable balance.
     * @throws GatewayRefundException If external payment refund gateway rejects or fails.
     * @throws \InvalidArgumentException If reservation does not exist.
     */
    public function cancel(string $reservationUid, CancellationRequest $request): CancellationResult;
}
