<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Quote;

/**
 * Contract for the authoritative Quotation Engine.
 */
interface QuoteEngineInterface
{
    /**
     * Calculates an authoritative Quote for a property and date span.
     *
     * @param string $propertyId Canonical property identifier ('1707' or '1606')
     * @param string $checkIn Check-in date in Y-m-d format
     * @param string $checkOut Check-out date in Y-m-d format
     * @return Quote The calculated quote result
     * @throws \InvalidArgumentException If input parameters or dates are structurally invalid
     */
    public function quote(string $propertyId, string $checkIn, string $checkOut): Quote;
}
