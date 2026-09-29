<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

/**
 * Primary domain contract for direct booking checkout and payment orchestration.
 */
interface BookingPaymentProcessorInterface
{
    /**
     * Orchestrates the direct booking checkout lifecycle:
     * 1. Validates inputs and dates chronology.
     * 2. Verifies availability against channel and reservation conflicts.
     * 3. Calculates authoritative pricing and enforces minimum stays via the Quote Engine.
     * 4. Enforces idempotency against duplicate charge submissions.
     * 5. Dispatches charges to the payment gateway port.
     * 6. Persists confirmed or pending reservations.
     * 7. Executes post-payment fulfillment and email workflows.
     *
     * @param BookingPaymentRequest $request
     * @return BookingPaymentResult
     */
    public function processBookingPayment(BookingPaymentRequest $request): BookingPaymentResult;
}
