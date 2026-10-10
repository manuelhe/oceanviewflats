<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation;

use Throwable;
use OceanViewFlats\Admin\Service\MercadoPagoRefundClient;
use OceanViewFlats\Admin\Service\MercadoPagoRefundClientInterface;
use OceanViewFlats\Admin\Service\MercadoPagoRefundException;
use OceanViewFlats\Domain\Reservation\GatewayRefundException;
use OceanViewFlats\Domain\Reservation\Port\PaymentRefundPort;
use OceanViewFlats\Domain\Reservation\RefundReceipt;

/**
 * Production Mercado Pago adapter for issuing payment refunds.
 */
final class MercadoPagoPaymentRefundAdapter implements PaymentRefundPort
{
    private MercadoPagoRefundClientInterface $client;

    public function __construct(
        ?MercadoPagoRefundClientInterface $client = null,
        ?string $accessToken = null
    ) {
        $this->client = $client ?? new MercadoPagoRefundClient(
            $accessToken ?? (string) (getenv('MP_ACCESS_TOKEN') ?: '')
        );
    }

    /**
     * @inheritDoc
     */
    public function issueRefund(string $paymentId, float $amountCop, string $idempotencyKey): RefundReceipt
    {
        try {
            $amount = $amountCop > 0.0 ? $amountCop : null;
            $result = $this->client->refundPayment($paymentId, $amount, $idempotencyKey);

            return new RefundReceipt(
                refundId: (string) $result['id'],
                paymentId: (string) $result['payment_id'],
                amountCop: (float) $result['amount'],
                status: (string) $result['status'],
                idempotencyKey: $idempotencyKey,
                rawResponse: $result
            );
        } catch (MercadoPagoRefundException $e) {
            throw GatewayRefundException::forPayment($paymentId, $amountCop, $e->getUserFriendlyMessage(), $e);
        } catch (Throwable $e) {
            throw GatewayRefundException::forPayment($paymentId, $amountCop, $e->getMessage(), $e);
        }
    }
}
