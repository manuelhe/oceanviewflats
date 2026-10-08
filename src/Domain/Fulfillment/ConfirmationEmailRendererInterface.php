<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

interface ConfirmationEmailRendererInterface
{
    /**
     * Renders the localized HTML confirmation email for the Primary Guest.
     * Strictly enforces ADR 0001: withholds door codes, Wi-Fi credentials, and direct Guide URLs.
     * Provides a direct, localized invitation link to the Guest Registry page (/registry/index.html, /registry/es.html, etc.).
     */
    public function renderGuestConfirmationHtml(Reservation $reservation): string;

    /**
     * Renders the localized subject line for the Primary Guest.
     */
    public function renderGuestSubject(Reservation $reservation): string;

    /**
     * Renders the host notification email HTML.
     */
    public function renderHostNotificationHtml(Reservation $reservation): string;

    /**
     * Renders the host notification subject line.
     */
    public function renderHostSubject(Reservation $reservation): string;
}
