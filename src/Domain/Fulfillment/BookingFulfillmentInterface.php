<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

interface BookingFulfillmentInterface
{
    /**
     * Executes post-settlement fulfillment for a confirmed reservation:
     * 1. Synchronizes confirmed state to Google Sheets.
     * 2. Dispatches localized Reservation Confirmation email to Primary Guest (strictly withholding access credentials per ADR 0001).
     * 3. Dispatches Host Notification email.
     *
     * @param Reservation $reservation
     * @param array<string, mixed> $extra Extra metadata (e.g. payment_id, status overrides)
     * @return FulfillmentResult
     */
    public function fulfillConfirmation(Reservation $reservation, array $extra = []): FulfillmentResult;
}
