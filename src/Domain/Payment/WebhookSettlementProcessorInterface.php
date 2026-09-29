<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

/**
 * Port contract for authoritative webhook settlement processor.
 * Encapsulates all five asynchronous settlement rules for incoming gateway notifications.
 */
interface WebhookSettlementProcessorInterface
{
    /**
     * Authoritatively processes and settles an incoming payment notification from the payment gateway.
     *
     * @param string $paymentId External gateway payment ID
     * @return WebhookSettlementResult
     */
    public function settlePayment(string $paymentId): WebhookSettlementResult;
}
