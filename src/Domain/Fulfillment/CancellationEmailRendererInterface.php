<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Contract for rendering localized cancellation transactional emails.
 * Supports primary guest cancellation notices and internal host operational notifications.
 */
interface CancellationEmailRendererInterface
{
    /**
     * Renders the localized HTML cancellation email for the Primary Guest.
     * Itemizes cancelled stay dates, property details, and financial refund breakdown
     * (refunded amount vs. policy retention).
     */
    public function renderGuestCancellationHtml(Reservation $reservation, float $refundAmount, float $policyRetention): string;

    /**
     * Renders the localized subject line for the Primary Guest.
     */
    public function renderGuestSubject(Reservation $reservation): string;

    /**
     * Renders the host notification email HTML for operational records, including the internal staff reason.
     */
    public function renderHostNotificationHtml(Reservation $reservation, float $refundAmount, float $policyRetention, string $reason): string;

    /**
     * Renders the host notification subject line.
     */
    public function renderHostSubject(Reservation $reservation): string;
}
