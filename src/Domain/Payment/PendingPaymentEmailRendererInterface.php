<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use OceanViewFlats\Domain\Quote\Quote;
use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Contract for rendering localized HTML emails containing pending payment instructions.
 */
interface PendingPaymentEmailRendererInterface
{
    /**
     * Renders localized HTML email for pending hold notifications.
     */
    public function renderPendingEmailHtml(
        Reservation $reservation,
        Quote $quote,
        PaymentGatewayResult $gatewayResult
    ): string;
}
