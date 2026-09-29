<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Service;

final class InMemoryMercadoPagoRefundClient implements MercadoPagoRefundClientInterface
{
    /**
     * @var list<array{
     *     payment_id: string,
     *     amount: float|null,
     *     idempotency_key: string
     * }>
     */
    private array $dispatchedRefunds = [];

    private bool $shouldFail = false;
    private int $failureStatusCode = 428;
    private string $failureMessage = 'Insufficient balance';
    private ?string $failureErrorCode = 'insufficient_money_for_refund';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $customResponse = null;

    private int $nextRefundId = 998877;

    public function setShouldFail(
        bool $shouldFail,
        int $statusCode = 428,
        string $message = 'Insufficient merchant balance',
        ?string $errorCode = 'insufficient_money_for_refund'
    ): self {
        $this->shouldFail = $shouldFail;
        $this->failureStatusCode = $statusCode;
        $this->failureMessage = $message;
        $this->failureErrorCode = $errorCode;
        return $this;
    }

    /**
     * @param array<string, mixed>|null $response
     */
    public function setCustomResponse(?array $response): self
    {
        $this->customResponse = $response;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function refundPayment(string $paymentId, ?float $amount, string $idempotencyKey): array
    {
        $this->dispatchedRefunds[] = [
            'payment_id' => $paymentId,
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
        ];

        if ($this->shouldFail) {
            throw new MercadoPagoRefundException(
                $this->failureMessage,
                $this->failureStatusCode,
                $this->failureErrorCode
            );
        }

        if ($this->customResponse !== null) {
            /** @var array{id: int|string, payment_id: int|string, amount: float, status: string, date_created?: string} */
            return $this->customResponse;
        }

        return [
            'id' => (string) ($this->nextRefundId++),
            'payment_id' => $paymentId,
            'amount' => $amount ?? 1000000.0,
            'status' => 'approved',
            'date_created' => date('c'),
        ];
    }

    /**
     * @return list<array{
     *     payment_id: string,
     *     amount: float|null,
     *     idempotency_key: string
     * }>
     */
    public function getDispatchedRefunds(): array
    {
        return $this->dispatchedRefunds;
    }

    public function clear(): void
    {
        $this->dispatchedRefunds = [];
        $this->shouldFail = false;
        $this->customResponse = null;
    }
}
