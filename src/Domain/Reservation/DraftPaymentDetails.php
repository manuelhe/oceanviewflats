<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Value object capturing payment parameters associated with a reservation draft.
 */
final class DraftPaymentDetails
{
    public function __construct(
        public readonly ?string $paymentMethodId = null,
        public readonly ?string $mercadopagoPreferenceId = null,
        public readonly ?string $mercadopagoPaymentId = null,
        public readonly ?string $paymentStatus = null,
        public readonly ?string $paymentDetail = null
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function create(
        ?string $paymentMethodId = null,
        ?string $mercadopagoPaymentId = null,
        ?string $paymentStatus = null,
        ?string $mercadopagoPreferenceId = null,
        ?string $paymentDetail = null
    ): self {
        return new self(
            $paymentMethodId,
            $mercadopagoPreferenceId,
            $mercadopagoPaymentId,
            $paymentStatus,
            $paymentDetail
        );
    }
}
