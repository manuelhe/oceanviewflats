<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Fulfillment\BookingFulfillment;
use OceanViewFlats\Domain\Fulfillment\BookingFulfillmentInterface;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRenderer;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\EmailSenderInterface;
use OceanViewFlats\Domain\Fulfillment\PhpMailSender;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PDO;
use Throwable;

/**
 * Authoritative domain service processing asynchronous external webhook settlements from payment gateway.
 *
 * Implements the five core settlement rules:
 * 1. Resurrection Defense: Rejects delayed payment confirmations for cancelled bookings.
 * 2. Full Refund Synchronization: Auto-cancels confirmed bookings, records itemized refund entries,
 *    logs audit trail records, and dispatches localized cancellation notices to primary guests.
 * 3. Partial Refund Synchronization: Updates balances and records line items while preserving active stay dates.
 * 4. Idempotency Replay Guard: Gracefully acknowledges duplicate webhook events without duplicating mutations or emails.
 * 5. Confirmation Fulfillment: Transitions pending reservations to confirmed, associates payment IDs,
 *    records audit history, and dispatches fulfillment without door codes (preserving ADR 0001).
 */
final class WebhookSettlementProcessor implements WebhookSettlementProcessorInterface
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PDO $pdo,
        private readonly BookingFulfillmentInterface $fulfillment,
        private readonly CancellationEmailRendererInterface $cancellationRenderer,
        private readonly EmailSenderInterface $emailSender,
        private readonly string $publicSiteUrl = 'https://oceanviewflats.com',
        private readonly string $hostNotificationEmail = 'reservas@oceanviewflats.com'
    ) {
    }

    public function getPublicSiteUrl(): string
    {
        return $this->publicSiteUrl;
    }

    public function getHostNotificationEmail(): string
    {
        return $this->hostNotificationEmail;
    }

    /**
     * Factory providing sensible production defaults while allowing complete test seam overrides.
     *
     * @param PDO $pdo
     * @param array<string, mixed> $options
     * @return self
     */
    public static function createDefault(PDO $pdo, array $options = []): self
    {
        $token = (string) (
            $options['mercadopago_access_token']
            ?? $_ENV['MERCADOPAGO_ACCESS_TOKEN']
            ?? $_SERVER['MERCADOPAGO_ACCESS_TOKEN']
            ?? getenv('MERCADOPAGO_ACCESS_TOKEN')
            ?: ''
        );
        $baseUrl = (string) (
            $options['mercadopago_base_url']
            ?? $_ENV['MERCADOPAGO_BASE_URL']
            ?? 'https://api.mercadopago.com'
        );
        $publicSiteUrl = (string) (
            $options['public_site_url']
            ?? $_ENV['PUBLIC_SITE_URL']
            ?? getenv('PUBLIC_SITE_URL')
            ?: 'https://oceanviewflats.com'
        );
        $hostNotificationEmail = (string) (
            $options['host_notification_email']
            ?? $_ENV['RECIPIENT_EMAIL']
            ?? getenv('RECIPIENT_EMAIL')
            ?: 'reservas@oceanviewflats.com'
        );

        /** @var PaymentGatewayInterface $gateway */
        $gateway = $options['gateway'] ?? new MercadoPagoPaymentGateway(
            accessToken: $token,
            baseUrl: $baseUrl
        );

        /** @var BookingFulfillmentInterface $fulfillment */
        $fulfillment = $options['fulfillment'] ?? BookingFulfillment::createDefault($hostNotificationEmail);

        /** @var CancellationEmailRendererInterface $cancellationRenderer */
        $cancellationRenderer = $options['cancellation_renderer'] ?? new CancellationEmailRenderer(baseUrl: $publicSiteUrl);

        /** @var EmailSenderInterface $emailSender */
        $emailSender = $options['email_sender'] ?? new PhpMailSender();

        return new self(
            gateway: $gateway,
            pdo: $pdo,
            fulfillment: $fulfillment,
            cancellationRenderer: $cancellationRenderer,
            emailSender: $emailSender,
            publicSiteUrl: $publicSiteUrl,
            hostNotificationEmail: $hostNotificationEmail
        );
    }

    /**
     * Authoritatively processes and settles an incoming payment notification from the payment gateway.
     *
     * @param string $paymentId External gateway payment ID
     * @return WebhookSettlementResult
     */
    public function settlePayment(string $paymentId): WebhookSettlementResult
    {
        $cleanPaymentId = trim($paymentId);
        if ($cleanPaymentId === '') {
            return WebhookSettlementResult::gatewayError(
                message: 'Payment ID cannot be empty',
                httpStatusCode: 400,
                data: ['payment_id' => $paymentId]
            );
        }

        // 1. Authoritative Gateway Lookup
        try {
            $details = $this->gateway->getPayment($cleanPaymentId);
        } catch (PaymentGatewayException $e) {
            $code = $e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 502;
            return WebhookSettlementResult::gatewayError(
                message: $e->getMessage(),
                httpStatusCode: $statusCode,
                data: ['payment_id' => $cleanPaymentId, 'error_type' => $e->getErrorType()]
            );
        } catch (Throwable $e) {
            return WebhookSettlementResult::gatewayError(
                message: 'Unexpected gateway communication failure: ' . $e->getMessage(),
                httpStatusCode: 502,
                data: ['payment_id' => $cleanPaymentId]
            );
        }

        // 2. Reservation External Reference Lookup
        $externalRef = trim($details->externalReference);
        if ($externalRef === '') {
            return WebhookSettlementResult::unknownReservation(
                reservationUid: null,
                data: ['payment_id' => $cleanPaymentId, 'status' => $details->status],
                message: 'Payment does not contain external_reference.'
            );
        }

        try {
            $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE reservation_uid = ? LIMIT 1');
            $stmt->execute([$externalRef]);
            /** @var array<string, mixed>|false $reservation */
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return WebhookSettlementResult::databaseError(
                message: 'Database query failure: ' . $e->getMessage(),
                httpStatusCode: 500,
                data: ['payment_id' => $cleanPaymentId, 'reservation_uid' => $externalRef],
                reservationUid: $externalRef
            );
        }

        if ($reservation === false) {
            return WebhookSettlementResult::unknownReservation(
                reservationUid: $externalRef,
                data: ['payment_id' => $cleanPaymentId, 'status' => $details->status],
                message: sprintf('Reservation %s not found in database.', $externalRef)
            );
        }

        $resStatus = (string) ($reservation['status'] ?? '');
        $resTotalPrice = (float) ($reservation['total_price'] ?? 0.0);
        $totalRefundedAmount = $details->totalRefundedAmount;

        // 3. Branch 1: Full Refund Synchronization (Rule 2)
        $isFullRefund = $details->isRefunded() || ($resTotalPrice > 0.0 && $totalRefundedAmount >= $resTotalPrice);
        if ($isFullRefund) {
            $actualRefundAmount = $totalRefundedAmount > 0.0 ? $totalRefundedAmount : $resTotalPrice;

            if ($resStatus !== 'cancelled') {
                try {
                    $this->pdo->beginTransaction();

                    $existingNotes = isset($reservation['notes']) && is_string($reservation['notes']) ? trim($reservation['notes']) : '';
                    $cancelNote = sprintf(
                        '[External Refund Sync %s] Full refund detected via webhook (COP %s)',
                        date('Y-m-d H:i'),
                        number_format($actualRefundAmount, 2)
                    );
                    $updatedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $cancelNote : $cancelNote;

                    $hasNotesCol = array_key_exists('notes', $reservation);
                    if ($hasNotesCol) {
                        $upStmt = $this->pdo->prepare("
                            UPDATE reservations 
                            SET status = 'cancelled', 
                                payment_status = 'refunded', 
                                refunded_amount = :refunded_amount, 
                                notes = :notes,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE reservation_uid = :uid
                        ");
                        $upStmt->execute([
                            ':uid' => $externalRef,
                            ':refunded_amount' => $actualRefundAmount,
                            ':notes' => $updatedNotes,
                        ]);
                    } else {
                        $upStmt = $this->pdo->prepare("
                            UPDATE reservations 
                            SET status = 'cancelled', 
                                payment_status = 'refunded', 
                                refunded_amount = :refunded_amount, 
                                updated_at = CURRENT_TIMESTAMP
                            WHERE reservation_uid = :uid
                        ");
                        $upStmt->execute([
                            ':uid' => $externalRef,
                            ':refunded_amount' => $actualRefundAmount,
                        ]);
                    }

                    $this->syncRefundItems($externalRef, $cleanPaymentId, $details->refunds, $actualRefundAmount);

                    $this->recordAuditLog(
                        action: 'refund_cancellation',
                        reservationUid: $externalRef,
                        payloadBefore: [
                            'status' => $resStatus,
                            'payment_status' => $reservation['payment_status'] ?? null,
                        ],
                        payloadAfter: [
                            'status' => 'cancelled',
                            'payment_status' => 'refunded',
                            'refunded_amount' => $actualRefundAmount,
                        ]
                    );

                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    return WebhookSettlementResult::databaseError(
                        message: 'Failed to process full refund cancellation: ' . $e->getMessage(),
                        httpStatusCode: 500,
                        data: ['payment_id' => $cleanPaymentId, 'reservation_uid' => $externalRef],
                        reservationUid: $externalRef
                    );
                }

                // Resilient post-commit cancellation email dispatch
                $this->dispatchCancellationEmail($reservation, $cleanPaymentId, $actualRefundAmount);

                return WebhookSettlementResult::refundCancelled(
                    reservationUid: $externalRef,
                    data: [
                        'payment_id' => $cleanPaymentId,
                        'refunded_amount' => $actualRefundAmount,
                        'reservation_status' => 'cancelled',
                    ]
                );
            }

            // Already cancelled: synchronize refund rows and update refunded amount if needed
            try {
                $upStmt = $this->pdo->prepare("
                    UPDATE reservations 
                    SET payment_status = 'refunded', 
                        refunded_amount = :refunded_amount, 
                        updated_at = CURRENT_TIMESTAMP
                    WHERE reservation_uid = :uid
                ");
                $upStmt->execute([
                    ':uid' => $externalRef,
                    ':refunded_amount' => $actualRefundAmount,
                ]);

                $this->syncRefundItems($externalRef, $cleanPaymentId, $details->refunds, $actualRefundAmount);
            } catch (Throwable $e) {
                return WebhookSettlementResult::databaseError(
                    message: 'Failed to sync refund items on cancelled reservation: ' . $e->getMessage(),
                    httpStatusCode: 500,
                    data: ['payment_id' => $cleanPaymentId, 'reservation_uid' => $externalRef],
                    reservationUid: $externalRef
                );
            }

            return WebhookSettlementResult::refundCancelled(
                reservationUid: $externalRef,
                data: [
                    'payment_id' => $cleanPaymentId,
                    'refunded_amount' => $actualRefundAmount,
                    'reservation_status' => 'cancelled',
                ],
                message: 'Reservation was already cancelled; synchronized refund items.'
            );
        }

        // 4. Branch 2: Partial Refund Synchronization (Rule 3)
        $isPartialRefund = $details->isPartiallyRefunded()
            || ($totalRefundedAmount > 0.0 && $totalRefundedAmount < $resTotalPrice);
        if ($isPartialRefund) {
            try {
                $this->pdo->beginTransaction();

                $upStmt = $this->pdo->prepare("
                    UPDATE reservations 
                    SET payment_status = 'partially_refunded', 
                        refunded_amount = :refunded_amount, 
                        updated_at = CURRENT_TIMESTAMP
                    WHERE reservation_uid = :uid
                ");
                $upStmt->execute([
                    ':uid' => $externalRef,
                    ':refunded_amount' => $totalRefundedAmount,
                ]);

                $this->syncRefundItems($externalRef, $cleanPaymentId, $details->refunds, $totalRefundedAmount);

                $this->recordAuditLog(
                    action: 'external_partial_refund',
                    reservationUid: $externalRef,
                    payloadBefore: [
                        'payment_status' => $reservation['payment_status'] ?? null,
                        'refunded_amount' => (float) ($reservation['refunded_amount'] ?? 0.0),
                    ],
                    payloadAfter: [
                        'payment_status' => 'partially_refunded',
                        'refunded_amount' => $totalRefundedAmount,
                    ]
                );

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return WebhookSettlementResult::databaseError(
                    message: 'Failed to synchronize partial refund: ' . $e->getMessage(),
                    httpStatusCode: 500,
                    data: ['payment_id' => $cleanPaymentId, 'reservation_uid' => $externalRef],
                    reservationUid: $externalRef
                );
            }

            return WebhookSettlementResult::partialRefundSynced(
                reservationUid: $externalRef,
                data: [
                    'payment_id' => $cleanPaymentId,
                    'refunded_amount' => $totalRefundedAmount,
                    'reservation_status' => $resStatus,
                ]
            );
        }

        // 5. Branch 3: Approved Payment
        if ($details->isApproved()) {
            // Rule 1: Resurrection Defense (cancelled is terminal)
            if ($resStatus === 'cancelled') {
                return WebhookSettlementResult::ignoredCancelled(
                    reservationUid: $externalRef,
                    data: [
                        'payment_id' => $cleanPaymentId,
                        'current_status' => $resStatus,
                    ]
                );
            }

            // Rule 4: Idempotency Replay Guard
            if ($resStatus === 'confirmed') {
                return WebhookSettlementResult::alreadyConfirmed(
                    reservationUid: $externalRef,
                    data: [
                        'payment_id' => $cleanPaymentId,
                        'current_status' => $resStatus,
                    ]
                );
            }

            // Rule 5: Confirmation Fulfillment
            try {
                $this->pdo->beginTransaction();

                $paymentMethodId = $details->paymentMethodId !== ''
                    ? $details->paymentMethodId
                    : (isset($reservation['payment_method_id']) ? (string) $reservation['payment_method_id'] : null);

                $upStmt = $this->pdo->prepare("
                    UPDATE reservations 
                    SET status = 'confirmed', 
                        payment_status = 'approved', 
                        mercadopago_payment_id = :pay_id, 
                        payment_method_id = :pay_method,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE reservation_uid = :uid
                ");
                $upStmt->execute([
                    ':uid' => $externalRef,
                    ':pay_id' => $cleanPaymentId,
                    ':pay_method' => $paymentMethodId,
                ]);

                $this->recordAuditLog(
                    action: 'payment_approved_webhook',
                    reservationUid: $externalRef,
                    payloadBefore: [
                        'status' => $resStatus,
                        'payment_status' => $reservation['payment_status'] ?? null,
                    ],
                    payloadAfter: [
                        'status' => 'confirmed',
                        'payment_status' => 'approved',
                        'mercadopago_payment_id' => $cleanPaymentId,
                        'payment_method_id' => $paymentMethodId,
                    ]
                );

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                return WebhookSettlementResult::databaseError(
                    message: 'Failed to confirm reservation: ' . $e->getMessage(),
                    httpStatusCode: 500,
                    data: ['payment_id' => $cleanPaymentId, 'reservation_uid' => $externalRef],
                    reservationUid: $externalRef
                );
            }

            // Post-settlement fulfillment via BookingFulfillmentInterface
            $confirmedReservation = new Reservation(
                reservationUid: (string) $reservation['reservation_uid'],
                propertyId: (string) $reservation['property_id'],
                guestName: (string) $reservation['guest_name'],
                guestEmail: (string) $reservation['guest_email'],
                guestPhone: (string) $reservation['guest_phone'],
                checkIn: (string) $reservation['check_in'],
                checkOut: (string) $reservation['check_out'],
                totalPrice: (float) $reservation['total_price'],
                status: ReservationStatus::CONFIRMED,
                paymentMethodId: $paymentMethodId,
                mercadopagoPaymentId: $cleanPaymentId,
                paymentStatus: 'approved',
                lang: isset($reservation['lang']) ? (string) $reservation['lang'] : 'en',
                createdAt: isset($reservation['created_at']) ? new DateTimeImmutable((string) $reservation['created_at']) : null,
                doorCode: null // strictly withholding per ADR 0001
            );

            $fulfillmentResult = $this->fulfillment->fulfillConfirmation($confirmedReservation, [
                'payment_id' => $cleanPaymentId,
                'payment_status' => 'approved',
            ]);

            return WebhookSettlementResult::confirmed(
                reservationUid: $externalRef,
                data: [
                    'payment_id' => $cleanPaymentId,
                    'fulfillment_success' => $fulfillmentResult->isSuccess(),
                    'fulfillment_errors' => $fulfillmentResult->errors,
                ]
            );
        }

        // 6. Default / Unrecognized Status
        return WebhookSettlementResult::unhandledStatus(
            status: $details->status,
            reservationUid: $externalRef,
            data: ['payment_id' => $cleanPaymentId]
        );
    }

    /**
     * @param array<int, RefundLineItem> $refunds
     */
    private function syncRefundItems(string $uid, string $paymentId, array $refunds, float $fallbackAmount): void
    {
        try {
            if (empty($refunds)) {
                if ($fallbackAmount > 0.0) {
                    $checkStmt = $this->pdo->prepare('SELECT COUNT(*) FROM reservation_refunds WHERE reservation_uid = :uid');
                    $checkStmt->execute([':uid' => $uid]);
                    if ((int) $checkStmt->fetchColumn() === 0) {
                        $ins = $this->pdo->prepare('
                            INSERT INTO reservation_refunds (
                                reservation_uid, mercadopago_refund_id, mercadopago_payment_id,
                                amount, status, reason, source, admin_user_id, created_at
                            ) VALUES (
                                :uid, NULL, :payment_id, :amount, "approved", "External refund synchronized via webhook", "mercadopago_webhook", NULL, CURRENT_TIMESTAMP
                            )
                        ');
                        $ins->execute([
                            ':uid' => $uid,
                            ':payment_id' => $paymentId !== '' ? $paymentId : 'external_webhook',
                            ':amount' => $fallbackAmount,
                        ]);
                    }
                }
                return;
            }

            $checkStmt = $this->pdo->prepare('SELECT COUNT(*) FROM reservation_refunds WHERE mercadopago_refund_id = :refund_id');
            $insStmt = $this->pdo->prepare('
                INSERT INTO reservation_refunds (
                    reservation_uid, mercadopago_refund_id, mercadopago_payment_id,
                    amount, status, reason, source, admin_user_id, created_at
                ) VALUES (
                    :uid, :refund_id, :payment_id, :amount, :status, :reason, "mercadopago_webhook", NULL, CURRENT_TIMESTAMP
                )
            ');

            foreach ($refunds as $refund) {
                $refundId = $refund->refundId !== '' ? $refund->refundId : null;
                if ($refundId !== null) {
                    $checkStmt->execute([':refund_id' => $refundId]);
                    if ((int) $checkStmt->fetchColumn() > 0) {
                        continue; // Already synced
                    }
                }

                $amount = $refund->amount > 0.0 ? $refund->amount : $fallbackAmount;
                if ($amount <= 0.0) {
                    continue;
                }

                $status = $refund->status !== '' ? $refund->status : 'approved';
                $insStmt->execute([
                    ':uid' => $uid,
                    ':refund_id' => $refundId,
                    ':payment_id' => $refund->paymentId !== '' ? $refund->paymentId : ($paymentId !== '' ? $paymentId : 'external_webhook'),
                    ':amount' => $amount,
                    ':status' => $status,
                    ':reason' => 'Mercado Pago external refund event',
                ]);
            }
        } catch (Throwable) {
            // Gracefully ignore if reservation_refunds table is not present
        }
    }

    /**
     * @param array<string, mixed>|null $payloadBefore
     * @param array<string, mixed>|null $payloadAfter
     */
    private function recordAuditLog(
        string $action,
        string $reservationUid,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null
    ): void {
        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO admin_audit_logs (
                    admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, created_at
                ) VALUES (
                    NULL, :action, "reservation", :entity_id, :payload_before, :payload_after, :ip_address, CURRENT_TIMESTAMP
                )
            ');
            $stmt->execute([
                ':action' => $action,
                ':entity_id' => $reservationUid,
                ':payload_before' => $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_SLASHES) : null,
                ':payload_after' => $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_SLASHES) : null,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {
            // Tolerant of missing audit logs table or non-fatal logging failure
        }
    }

    /**
     * @param array<string, mixed> $reservation
     */
    private function dispatchCancellationEmail(array $reservation, string $paymentId, float $actualRefundAmount): void
    {
        $totPrice = (float) ($reservation['total_price'] ?? 0.0);
        $policyRetention = max(0.0, round($totPrice - $actualRefundAmount, 2));

        $cancelledReservation = new Reservation(
            reservationUid: (string) $reservation['reservation_uid'],
            propertyId: (string) $reservation['property_id'],
            guestName: (string) $reservation['guest_name'],
            guestEmail: (string) $reservation['guest_email'],
            guestPhone: (string) $reservation['guest_phone'],
            checkIn: (string) $reservation['check_in'],
            checkOut: (string) $reservation['check_out'],
            totalPrice: $totPrice,
            status: ReservationStatus::CANCELLED,
            paymentMethodId: isset($reservation['payment_method_id']) ? (string) $reservation['payment_method_id'] : null,
            mercadopagoPaymentId: $paymentId !== '' ? $paymentId : null,
            paymentStatus: 'refunded',
            lang: isset($reservation['lang']) ? (string) $reservation['lang'] : 'en',
            createdAt: isset($reservation['created_at']) ? new DateTimeImmutable((string) $reservation['created_at']) : null
        );

        try {
            $guestSubject = $this->cancellationRenderer->renderGuestSubject($cancelledReservation);
            $guestHtml = $this->cancellationRenderer->renderGuestCancellationHtml($cancelledReservation, $actualRefundAmount, $policyRetention);
            $mailSent = $this->emailSender->send($cancelledReservation->guestEmail, $guestSubject, $guestHtml);

            if ($mailSent) {
                $this->recordAuditLog(
                    action: 'cancellation_email_sent',
                    reservationUid: $cancelledReservation->reservationUid,
                    payloadAfter: [
                        'recipient' => $cancelledReservation->guestEmail,
                        'refund_amount' => $actualRefundAmount,
                        'policy_retention' => $policyRetention,
                        'trigger' => 'mercadopago_webhook',
                    ]
                );
            } else {
                $this->recordAuditLog(
                    action: 'email_delivery_failed',
                    reservationUid: $cancelledReservation->reservationUid,
                    payloadAfter: [
                        'recipient' => $cancelledReservation->guestEmail,
                        'error' => 'Email sender returned false',
                        'trigger' => 'mercadopago_webhook',
                    ]
                );
            }
        } catch (Throwable $e) {
            $this->recordAuditLog(
                action: 'email_delivery_failed',
                reservationUid: $cancelledReservation->reservationUid,
                payloadAfter: [
                    'error' => $e->getMessage(),
                    'trigger' => 'mercadopago_webhook',
                ]
            );
        }
    }
}
