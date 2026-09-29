<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

/**
 * Port contract for the external payment gateway.
 * Defines seams for charging reservations and verifying transaction settlement state.
 */
interface PaymentGatewayInterface
{
    /**
     * Dispatches a payment intent to the external gateway.
     *
     * @param PaymentIntent $intent
     * @return PaymentGatewayResult
     * @throws PaymentGatewayException If gateway communication fails or responds with a critical error
     */
    public function createPayment(PaymentIntent $intent): PaymentGatewayResult;

    /**
     * Fetches authoritative payment details and refund history by external payment ID.
     *
     * @param string $paymentId
     * @return PaymentDetails
     * @throws PaymentGatewayException If the query fails or payment is not found
     */
    public function getPayment(string $paymentId): PaymentDetails;
}
