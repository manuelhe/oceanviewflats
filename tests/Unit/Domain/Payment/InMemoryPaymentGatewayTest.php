<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Payment;

use OceanViewFlats\Domain\Payment\InMemoryPaymentGateway;
use OceanViewFlats\Domain\Payment\PaymentDetails;
use OceanViewFlats\Domain\Payment\PaymentGatewayException;
use OceanViewFlats\Domain\Payment\PaymentGatewayResult;
use OceanViewFlats\Domain\Payment\PaymentIntent;
use OceanViewFlats\Domain\Payment\RefundLineItem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemoryPaymentGatewayTest extends TestCase
{
    private InMemoryPaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new InMemoryPaymentGateway();
    }

    public function testDefaultCreatePaymentApprovesAndRecordsIntent(): void
    {
        $intent = new PaymentIntent(
            reservationUid: 'ovf_inmem_1',
            transactionAmount: 850000.0,
            paymentMethodId: 'visa',
            payerEmail: 'guest@inmem.com'
        );

        $result = $this->gateway->createPayment($intent);

        $this->assertTrue($result->success);
        $this->assertTrue($result->isApproved());
        $this->assertSame('approved', $result->status);
        $this->assertSame('visa', $result->paymentMethodId);
        $this->assertSame(850000.0, $result->transactionAmount);
        $this->assertNotNull($result->paymentId);

        $recorded = $this->gateway->getRecordedIntents();
        $this->assertCount(1, $recorded);
        $this->assertSame($intent, $recorded[0]);

        // Details should also be automatically retrievable
        $details = $this->gateway->getPayment($result->paymentId);
        $this->assertSame($result->paymentId, $details->paymentId);
        $this->assertSame('approved', $details->status);
        $this->assertSame('ovf_inmem_1', $details->externalReference);
    }

    public function testStagePaymentAndRetrieveDetails(): void
    {
        $details = new PaymentDetails(
            paymentId: 'staged_pay_123',
            status: 'approved',
            statusDetail: 'accredited',
            externalReference: 'ovf_staged_ref',
            transactionAmount: 1200000.0,
            totalRefundedAmount: 0.0,
            paymentMethodId: 'master',
            refunds: []
        );

        $this->gateway->stagePayment($details);

        $retrieved = $this->gateway->getPayment('staged_pay_123');
        $this->assertSame($details, $retrieved);
    }

    public function testStagePaymentArrayHelper(): void
    {
        $raw = [
            'id' => 998877,
            'status' => 'refunded',
            'status_detail' => 'refunded',
            'external_reference' => 'ovf_refund_array',
            'transaction_amount' => 500000.0,
            'transaction_amount_refunded' => 500000.0,
            'payment_method_id' => 'efecty',
            'refunds' => [
                [
                    'id' => 112233,
                    'payment_id' => 998877,
                    'amount' => 500000.0,
                    'status' => 'approved',
                    'date_created' => '2026-09-29T18:00:00Z',
                ],
            ],
        ];

        $this->gateway->stagePaymentArray($raw);

        $retrieved = $this->gateway->getPayment('998877');
        $this->assertSame('998877', $retrieved->paymentId);
        $this->assertSame('refunded', $retrieved->status);
        $this->assertTrue($retrieved->isRefunded());
        $this->assertCount(1, $retrieved->refunds);
        $this->assertInstanceOf(RefundLineItem::class, $retrieved->refunds[0]);
    }

    public function testStageCreateResultCustomOutcome(): void
    {
        $customResult = new PaymentGatewayResult(
            success: false,
            paymentId: 'rejected_pay_456',
            status: 'rejected',
            statusDetail: 'cc_rejected_insufficient_amount',
            paymentMethodId: 'visa',
            transactionAmount: 300000.0,
            errorMessage: 'Insufficient funds'
        );

        $this->gateway->stageCreateResult($customResult);

        $intent = new PaymentIntent(
            reservationUid: 'ovf_rejected',
            transactionAmount: 300000.0,
            paymentMethodId: 'visa',
            payerEmail: 'fail@example.com'
        );

        $result = $this->gateway->createPayment($intent);

        $this->assertSame($customResult, $result);
        $this->assertFalse($result->success);
        $this->assertTrue($result->isRejected());
    }

    public function testStageExceptionOnCreatePayment(): void
    {
        $exception = new PaymentGatewayException('Gateway timeout during payment processing', 504, 'network_timeout');
        $this->gateway->stageException($exception);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Gateway timeout');

        $this->gateway->createPayment(new PaymentIntent(
            reservationUid: 'ovf_exc',
            transactionAmount: 100000.0,
            paymentMethodId: 'pse',
            payerEmail: 'ex@example.com'
        ));
    }

    public function testStageExceptionOnGetPayment(): void
    {
        $exception = new RuntimeException('Unexpected internal error');
        $this->gateway->stageException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected internal error');

        $this->gateway->getPayment('any_id');
    }

    public function testGetPaymentThrows404WhenNotFound(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(404);

        try {
            $this->gateway->getPayment('unknown_id_999');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(404, $e->getHttpCode());
            $this->assertSame('payment_not_found', $e->getErrorType());
            throw $e;
        }
    }

    public function testClearResetsAllState(): void
    {
        $intent = new PaymentIntent(
            reservationUid: 'ovf_to_clear',
            transactionAmount: 400000.0,
            paymentMethodId: 'visa',
            payerEmail: 'clear@example.com'
        );

        $result = $this->gateway->createPayment($intent);
        $this->assertCount(1, $this->gateway->getRecordedIntents());
        $this->assertNotNull($result->paymentId);
        $this->assertSame('approved', $this->gateway->getPayment($result->paymentId)->status);

        $this->gateway->stageCreateResult(new PaymentGatewayResult(
            success: true,
            paymentId: 'staged',
            status: 'approved',
            statusDetail: 'accredited',
            paymentMethodId: 'visa',
            transactionAmount: 100.0
        ));
        $this->gateway->stageException(new RuntimeException('Error'));

        $this->gateway->clear();

        $this->assertEmpty($this->gateway->getRecordedIntents());

        // Exception should be cleared, but querying missing ID should throw standard 404
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(404);
        $this->gateway->getPayment('staged');
    }
}
