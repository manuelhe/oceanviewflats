<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Contract for rendering localized Access Dispatch notices delivering the unlocked
 * Guest Guide link and temporal smart lock door PIN to the Primary Guest.
 * Strictly adheres to ADR 0001 (dispatched only after Guest Registry is submitted).
 */
interface AccessDispatchEmailRendererInterface
{
    /**
     * Renders the localized subject line for the Primary Guest Access Dispatch email.
     */
    public function renderSubject(Reservation $reservation, ?string $lang = null): string;

    /**
     * Renders the localized plain-text Access Dispatch email body for the Primary Guest.
     */
    public function renderPlainText(
        Reservation $reservation,
        string $doorCode,
        ?string $guideUrl = null,
        ?string $lang = null,
        ?string $recipientName = null,
        ?string $parkingSpot = null
    ): string;

    /**
     * Renders the localized HTML Access Dispatch email body for the Primary Guest.
     */
    public function renderHtml(
        Reservation $reservation,
        string $doorCode,
        ?string $guideUrl = null,
        ?string $lang = null,
        ?string $recipientName = null,
        ?string $parkingSpot = null
    ): string;

    /**
     * Constructs the direct URL to the unlocked Guest Guide.
     */
    public function buildGuideUrl(Reservation $reservation, ?string $lang = null): string;
}
