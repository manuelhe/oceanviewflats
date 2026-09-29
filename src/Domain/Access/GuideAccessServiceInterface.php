<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Access;

/**
 * Service contract for server-gated Guest Guide access verification (ADR 0001).
 */
interface GuideAccessServiceInterface
{
    /**
     * Evaluates access permissions and returns physical/network credentials
     * strictly when the reservation is confirmed and the Guest Registry has been submitted.
     */
    public function verifyAccess(string $reservationUid, string $lang = 'en'): AccessVerificationResult;
}
