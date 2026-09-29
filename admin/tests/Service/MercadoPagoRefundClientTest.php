<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Service;

use OceanViewFlats\Admin\Service\InMemoryMercadoPagoRefundClient;
use OceanViewFlats\Admin\Service\MercadoPagoRefundClient;
use OceanViewFlats\Admin\Service\MercadoPagoRefundException;
use PHPUnit\Framework\TestCase;

final class MercadoPagoRefundClientTest extends TestCase
{
    public function testExceptionFriendlyMessages(): void
    {
        $ex428 = new MercadoPagoRefundException('insufficient_amount', 428, 'insufficient_money_for_refund');
        $this->assertSame(428, $ex428->getStatusCode());
        $this->assertSame('insufficient_money_for_refund', $ex428->getErrorCode());
        $this->assertStringContainsString('Insufficient merchant balance', $ex428->getUserFriendlyMessage());

        $ex422 = new MercadoPagoRefundException('expired', 422, 'refund_period_exceeded');
        $this->assertStringContainsString('refund window for this payment has expired', $ex422->getUserFriendlyMessage());

        $ex409 = new MercadoPagoRefundException('disputed', 409, 'active_dispute');
        $this->assertStringContainsString('active chargeback or dispute', $ex409->getUserFriendlyMessage());

        $ex504 = new MercadoPagoRefundException('timeout', 504, 'network_timeout');
        $this->assertStringContainsString('Network Error: Connection timed out', $ex504->getUserFriendlyMessage());

        $exGeneric = new MercadoPagoRefundException('Custom error', 500);
        $this->assertStringContainsString('Custom error', $exGeneric->getUserFriendlyMessage());
    }

    public function testInMemoryClientSuccessAndTracking(): void
    {
        $client = new InMemoryMercadoPagoRefundClient();

        $result1 = $client->refundPayment('mp-pay-123', null, 'idem-key-1');
        $this->assertSame('mp-pay-123', $result1['payment_id']);
        $this->assertSame('approved', $result1['status']);
        $this->assertSame(1000000.0, $result1['amount']);

        $result2 = $client->refundPayment('mp-pay-456', 250000.0, 'idem-key-2');
        $this->assertSame('mp-pay-456', $result2['payment_id']);
        $this->assertSame(250000.0, $result2['amount']);

        $history = $client->getDispatchedRefunds();
        $this->assertCount(2, $history);
        $this->assertSame('idem-key-1', $history[0]['idempotency_key']);
        $this->assertNull($history[0]['amount']);
        $this->assertSame('idem-key-2', $history[1]['idempotency_key']);
        $this->assertSame(250000.0, $history[1]['amount']);
    }

    public function testInMemoryClientFailureSimulation(): void
    {
        $client = new InMemoryMercadoPagoRefundClient();
        $client->setShouldFail(true, 428, 'Collector has insufficient balance', 'insufficient_money_for_refund');

        $this->expectException(MercadoPagoRefundException::class);
        $this->expectExceptionCode(428);
        $this->expectExceptionMessage('Collector has insufficient balance');

        $client->refundPayment('mp-pay-123', 50000.0, 'idem-key-99');
    }

    public function testProductionClientRejectsEmptyPaymentId(): void
    {
        $client = new MercadoPagoRefundClient('test_token');

        $this->expectException(MercadoPagoRefundException::class);
        $this->expectExceptionMessage('Payment ID cannot be empty');

        $client->refundPayment('  ', 100.0, 'idem-1');
    }

    public function testProductionClientRejectsEmptyIdempotencyKey(): void
    {
        $client = new MercadoPagoRefundClient('test_token');

        $this->expectException(MercadoPagoRefundException::class);
        $this->expectExceptionMessage('Idempotency key cannot be empty');

        $client->refundPayment('pay-123', 100.0, '  ');
    }

    public function testProductionClientNetworkFailureThrowsException(): void
    {
        // Point to an unrouteable localhost port to test network failure handling
        $client = new MercadoPagoRefundClient('test_token', 'http://127.0.0.1:1', timeoutSeconds: 1);

        $this->expectException(MercadoPagoRefundException::class);
        $this->expectExceptionCode(504);

        $client->refundPayment('pay-123', 100.0, 'idem-test');
    }
}
