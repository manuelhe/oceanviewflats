<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use Throwable;

/**
 * In-memory test stand-in implementing PaymentGatewayInterface.
 * Enables zero-network, sub-millisecond unit and integration testing.
 */
final class InMemoryPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @var list<PaymentIntent>
     */
    private array $recordedIntents = [];

    /**
     * @var array<string, PaymentDetails>
     */
    private array $payments = [];

    private ?PaymentGatewayResult $stagedCreateResult = null;
    private ?Throwable $stagedException = null;

    /**
     * Pre-stages an authoritative payment details record for getPayment().
     */
    public function stagePayment(PaymentDetails $details): void
    {
        $this->payments[$details->paymentId] = $details;
    }

    /**
     * Convenience helper to stage raw Mercado Pago JSON array into PaymentDetails.
     *
     * @param array<string, mixed> $data
     */
    public function stagePaymentArray(array $data): void
    {
        $this->stagePayment(PaymentDetails::fromArray($data));
    }

    /**
     * Pre-stages the next result returned by createPayment().
     */
    public function stageCreateResult(PaymentGatewayResult $result): void
    {
        $this->stagedCreateResult = $result;
    }

    /**
     * Pre-stages an exception to be thrown by any gateway operation.
     */
    public function stageException(Throwable $e): void
    {
        $this->stagedException = $e;
    }

    /**
     * @return list<PaymentIntent>
     */
    public function getRecordedIntents(): array
    {
        return $this->recordedIntents;
    }

    /**
     * Clears all recorded intents and staged responses.
     */
    public function clear(): void
    {
        $this->recordedIntents = [];
        $this->payments = [];
        $this->stagedCreateResult = null;
        $this->stagedException = null;
    }

    /**
     * @inheritDoc
     */
    public function createPayment(PaymentIntent $intent): PaymentGatewayResult
    {
        if ($this->stagedException !== null) {
            throw $this->stagedException;
        }

        $this->recordedIntents[] = $intent;

        if ($this->stagedCreateResult !== null) {
            return $this->stagedCreateResult;
        }

        $syntheticPaymentId = 'mock_pay_' . ($intent->reservationUid !== '' ? $intent->reservationUid : uniqid());
        $raw = [
            'id' => $syntheticPaymentId,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'external_reference' => $intent->reservationUid,
            'transaction_amount' => $intent->transactionAmount,
            'payment_method_id' => $intent->paymentMethodId,
        ];

        $result = new PaymentGatewayResult(
            success: true,
            paymentId: $syntheticPaymentId,
            status: 'approved',
            statusDetail: 'accredited',
            paymentMethodId: $intent->paymentMethodId,
            transactionAmount: $intent->transactionAmount,
            rawResponse: $raw
        );

        $this->payments[$syntheticPaymentId] = PaymentDetails::fromArray($raw);

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function getPayment(string $paymentId): PaymentDetails
    {
        if ($this->stagedException !== null) {
            throw $this->stagedException;
        }

        $cleanPaymentId = trim($paymentId);
        if (isset($this->payments[$cleanPaymentId])) {
            return $this->payments[$cleanPaymentId];
        }

        throw new PaymentGatewayException(
            "Payment {$cleanPaymentId} not found in in-memory gateway store",
            404,
            'payment_not_found'
        );
    }
}
