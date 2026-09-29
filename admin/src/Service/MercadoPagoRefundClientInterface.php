<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Service;

interface MercadoPagoRefundClientInterface
{
    /**
     * Dispatches a refund request to Mercado Pago for the given payment ID.
     *
     * @param string $paymentId External Mercado Pago payment ID.
     * @param float|null $amount Refund amount in COP. If null, requests a full refund.
     * @param string $idempotencyKey Unique client idempotency key header.
     * @return array{
     *     id: int|string,
     *     payment_id: int|string,
     *     amount: float,
     *     status: string,
     *     date_created?: string
     * }
     * @throws MercadoPagoRefundException On gateway rejection, authorization failure, or network failure.
     */
    public function refundPayment(string $paymentId, ?float $amount, string $idempotencyKey): array;
}
