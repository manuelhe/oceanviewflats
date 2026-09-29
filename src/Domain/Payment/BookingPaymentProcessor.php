<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use DateTimeImmutable;
use InvalidArgumentException;
use OceanViewFlats\Domain\Fulfillment\BookingFulfillment;
use OceanViewFlats\Domain\Fulfillment\BookingFulfillmentInterface;
use OceanViewFlats\Domain\Fulfillment\EmailSenderInterface;
use OceanViewFlats\Domain\Fulfillment\PhpMailSender;
use OceanViewFlats\Domain\Quote\Quote;
use OceanViewFlats\Domain\Quote\QuoteEngine;
use OceanViewFlats\Domain\Quote\QuoteEngineInterface;
use OceanViewFlats\Domain\Reservation\PdoReservationRepository;
use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Reservation\ReservationLedger;
use OceanViewFlats\Domain\Reservation\ReservationLedgerInterface;
use OceanViewFlats\Domain\Reservation\ReservationRepositoryInterface;
use OceanViewFlats\Domain\Reservation\ReservationStatus;
use PDO;
use Throwable;

/**
 * Direct Booking Payment Processor.
 * Orchestrates the full lifecycle of a direct guest checkout:
 * - Input validation & hygiene
 * - Calendar availability verification (channel blocks, maintenance blocks, reservation holds)
 * - Authoritative quote computation & minimum stay enforcement
 * - Idempotency double-charge shield
 * - Payment gateway charge dispatch
 * - Reservation persistence
 * - Post-payment fulfillment and email dispatch
 */
final class BookingPaymentProcessor implements BookingPaymentProcessorInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private readonly array $translations;

    /**
     * @param PaymentGatewayInterface $gateway
     * @param PDO $pdo
     * @param ReservationLedgerInterface $ledger
     * @param QuoteEngineInterface $quoteEngine
     * @param ReservationRepositoryInterface $repository
     * @param BookingFulfillmentInterface $fulfillment
     * @param EmailSenderInterface $emailSender
     * @param string $publicSiteUrl
     * @param string $hostNotificationEmail
     * @param array<string, array<string, mixed>>|null $translations
     */
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly PDO $pdo,
        private readonly ReservationLedgerInterface $ledger,
        private readonly QuoteEngineInterface $quoteEngine,
        private readonly ReservationRepositoryInterface $repository,
        private readonly BookingFulfillmentInterface $fulfillment,
        private readonly EmailSenderInterface $emailSender,
        private readonly string $publicSiteUrl = 'https://oceanviewflats.com',
        private readonly string $hostNotificationEmail = 'reservas@oceanviewflats.com',
        ?array $translations = null
    ) {
        if ($translations !== null) {
            $this->translations = $translations;
        } else {
            $transFile = dirname(__DIR__, 3) . '/public/api/translations.php';
            $this->translations = file_exists($transFile) ? (require $transFile) : [];
        }
    }

    /**
     * Factory creating a processor with production defaults.
     *
     * @param PDO $pdo
     * @param array<string, mixed> $options
     * @return self
     */
    public static function createDefault(PDO $pdo, array $options = []): self
    {
        $publicSiteUrl = (string) ($options['publicSiteUrl'] ?? 'https://oceanviewflats.com');
        $hostNotificationEmail = (string) ($options['hostNotificationEmail'] ?? 'reservas@oceanviewflats.com');

        $gateway = $options['gateway'] ?? new MercadoPagoPaymentGateway(
            accessToken: (string) ($options['mpAccessToken'] ?? (getenv('MERCADOPAGO_ACCESS_TOKEN') ?: ''))
        );

        $ledger = $options['ledger'] ?? ReservationLedger::createDefault($pdo, $options['cacheDir'] ?? null);
        $quoteEngine = $options['quoteEngine'] ?? QuoteEngine::createDefault($options['csvPath'] ?? null);
        $repository = $options['repository'] ?? new PdoReservationRepository($pdo);
        $fulfillment = $options['fulfillment'] ?? BookingFulfillment::createDefault($hostNotificationEmail);
        $emailSender = $options['emailSender'] ?? new PhpMailSender();
        $translations = $options['translations'] ?? null;

        return new self(
            gateway: $gateway,
            pdo: $pdo,
            ledger: $ledger,
            quoteEngine: $quoteEngine,
            repository: $repository,
            fulfillment: $fulfillment,
            emailSender: $emailSender,
            publicSiteUrl: $publicSiteUrl,
            hostNotificationEmail: $hostNotificationEmail,
            translations: $translations
        );
    }

    /**
     * @inheritDoc
     */
    public function processBookingPayment(BookingPaymentRequest $request): BookingPaymentResult
    {
        $lang = $request->lang;

        // 1. Input hygiene & validation
        $propertyId = trim($request->propertyId);
        if ($propertyId !== '1606' && $propertyId !== '1707') {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_property', 'Invalid property selected.'),
                400
            );
        }

        $guestName = trim($request->guestName);
        if (strlen($guestName) < 3) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_name', 'Please enter your full name (minimum 3 characters).'),
                400
            );
        }

        $guestEmail = trim($request->guestEmail);
        if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_email', 'Please enter a valid email address.'),
                400
            );
        }

        $guestPhone = trim($request->guestPhone);
        if (strlen($guestPhone) < 6) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_phone', 'Please enter a valid phone number.'),
                400
            );
        }

        $paymentMethodId = trim($request->paymentMethodId);
        if ($paymentMethodId === '') {
            return BookingPaymentResult::error('Payment method is required.', 400);
        }

        $checkInTime = strtotime($request->checkIn);
        $checkOutTime = strtotime($request->checkOut);

        if (!$checkInTime || !$checkOutTime || $checkInTime >= $checkOutTime) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_dates_invalid', 'Please enter a valid check-in and check-out range.'),
                400
            );
        }

        if ($checkInTime < strtotime(date('Y-m-d'))) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_dates_past', 'Check-in date cannot be in the past.'),
                400
            );
        }

        // 2. Availability verification via ReservationLedger
        $channelConflict = $this->ledger->findChannelConflict($propertyId, $request->checkIn, $request->checkOut);
        if ($channelConflict !== null) {
            $msgTpl = $this->trans(
                $lang,
                'booking',
                'err_overlap_airbnb',
                'The selected dates overlap with an existing Airbnb booking (%s). Please choose other dates.'
            );
            return BookingPaymentResult::conflict(sprintf($msgTpl, $channelConflict->startDate));
        }

        $maintenanceConflict = $this->ledger->findMaintenanceConflict($propertyId, $request->checkIn, $request->checkOut);
        if ($maintenanceConflict !== null) {
            return BookingPaymentResult::conflict(
                'The selected dates overlap with scheduled maintenance. Please select another range.'
            );
        }

        $resConflict = $this->ledger->findReservationConflict($propertyId, $request->checkIn, $request->checkOut);
        if ($resConflict !== null) {
            return BookingPaymentResult::conflict(
                $this->trans(
                    $lang,
                    'booking',
                    'err_overlap_db',
                    'The selected dates are already locked in our direct booking system. Please select another range.'
                )
            );
        }

        // 3. Authoritative Quote computation via QuoteEngine (ADR 0004)
        try {
            $quote = $this->quoteEngine->quote($propertyId, $request->checkIn, $request->checkOut);
        } catch (InvalidArgumentException $e) {
            return BookingPaymentResult::error(
                $this->trans($lang, 'booking', 'err_dates_invalid', $e->getMessage()),
                400
            );
        }

        if (!$quote->isValid()) {
            $msgTpl = $this->trans(
                $lang,
                'booking',
                'err_min_stay',
                'The minimum stay for the selected season is %d nights. Your requested stay is %d nights.'
            );
            return BookingPaymentResult::error(
                sprintf($msgTpl, $quote->minimumStayRequired(), $quote->nightsCount()),
                422
            );
        }

        // 4. Idempotency double-charge shield check
        if ($request->idempotencyKey !== null && trim($request->idempotencyKey) !== '') {
            $existingPaymentId = $this->findExistingIdempotency(trim($request->idempotencyKey));
            if ($existingPaymentId !== null) {
                $existingReservation = $this->repository->findByUid(trim($request->idempotencyKey));
                $resCode = $existingReservation !== null ? $existingReservation->reservationUid : trim($request->idempotencyKey);
                $resStatus = $existingReservation !== null ? $existingReservation->status->value : 'approved';

                return BookingPaymentResult::confirmed(
                    reservationUid: $resCode,
                    message: 'Payment already processed successfully',
                    extra: [
                        'payment_id' => $existingPaymentId,
                        'status' => $resStatus,
                    ]
                );
            }
        }

        // 5. Generate unique reservation business identifier
        $uid = 'ovf_' . bin2hex(random_bytes(4));
        $effectiveIdempotencyKey = ($request->idempotencyKey !== null && trim($request->idempotencyKey) !== '')
            ? trim($request->idempotencyKey)
            : $uid;

        // 6. Build PaymentIntent and dispatch to payment gateway port
        $intent = new PaymentIntent(
            reservationUid: $uid,
            transactionAmount: $quote->totalCop(),
            paymentMethodId: $paymentMethodId,
            payerEmail: $guestEmail,
            token: $request->token,
            installments: $request->installments,
            issuerId: $request->issuerId,
            identificationType: $request->identificationType,
            identificationNumber: $request->identificationNumber,
            financialInstitution: $request->financialInstitution,
            description: 'Reserva OceanViewFlats Apto ' . $propertyId,
            notificationUrl: rtrim($this->publicSiteUrl, '/') . '/api/mercadopago-webhook.php',
            idempotencyKey: $effectiveIdempotencyKey,
            ipAddress: $request->clientIp,
            payerEntityType: ($paymentMethodId === 'pse' ? 'individual' : null)
        );

        try {
            $gatewayResult = $this->gateway->createPayment($intent);
        } catch (PaymentGatewayException $e) {
            return BookingPaymentResult::error(
                'Direct payment declined: ' . $e->getMessage(),
                $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 502
            );
        } catch (Throwable $e) {
            return BookingPaymentResult::error(
                'Gateway communication error: ' . $e->getMessage(),
                500
            );
        }

        // 7. Gateway rejection handling
        if ($gatewayResult->isRejected()) {
            $errorMsg = $gatewayResult->errorMessage ?? 'Payment was declined or rejected';
            return BookingPaymentResult::rejected(
                message: $errorMsg,
                statusDetail: $gatewayResult->statusDetail,
                extra: array_filter([
                    'payment_id' => $gatewayResult->paymentId,
                ], fn($v) => $v !== null),
                httpStatusCode: 400
            );
        }

        // 8. Gateway approval handling
        if ($gatewayResult->isApproved()) {
            $reservation = new Reservation(
                reservationUid: $uid,
                propertyId: $propertyId,
                guestName: $guestName,
                guestEmail: $guestEmail,
                guestPhone: $guestPhone,
                checkIn: $request->checkIn,
                checkOut: $request->checkOut,
                totalPrice: $quote->totalCop(),
                status: ReservationStatus::CONFIRMED,
                paymentMethodId: $gatewayResult->paymentMethodId ?? $paymentMethodId,
                mercadopagoPaymentId: $gatewayResult->paymentId,
                paymentStatus: 'approved',
                paymentDetail: $gatewayResult->statusDetail,
                lang: $lang,
                createdAt: new DateTimeImmutable()
            );

            $this->repository->save($reservation);

            $this->recordAuditLog(
                action: 'reservation_created_confirmed',
                uid: $uid,
                payloadBefore: null,
                payloadAfter: [
                    'reservation_uid' => $uid,
                    'property_id' => $propertyId,
                    'check_in' => $request->checkIn,
                    'check_out' => $request->checkOut,
                    'total_price' => $quote->totalCop(),
                    'status' => 'confirmed',
                    'payment_id' => $gatewayResult->paymentId,
                    'payment_status' => 'approved',
                ],
                clientIp: $request->clientIp
            );

            $this->recordIdempotency($effectiveIdempotencyKey, (string) ($gatewayResult->paymentId ?? $uid));
            if ($effectiveIdempotencyKey !== $uid) {
                $this->recordIdempotency($uid, (string) ($gatewayResult->paymentId ?? $uid));
            }

            // Post-settlement fulfillment (spreadsheet sync & confirmation emails withholding credentials per ADR 0001)
            $this->fulfillment->fulfillConfirmation($reservation, [
                'payment_id' => $gatewayResult->paymentId,
                'payment_status' => 'approved',
            ]);

            $extra = [
                'payment_id' => $gatewayResult->paymentId,
                'status' => 'approved',
            ];

            return BookingPaymentResult::confirmed(
                reservationUid: $uid,
                message: 'Payment request processed successfully',
                extra: $extra
            );
        }

        // 9. Gateway pending handling (cash vouchers, PSE asynchronous clearing)
        if ($gatewayResult->isPending()) {
            $reservation = new Reservation(
                reservationUid: $uid,
                propertyId: $propertyId,
                guestName: $guestName,
                guestEmail: $guestEmail,
                guestPhone: $guestPhone,
                checkIn: $request->checkIn,
                checkOut: $request->checkOut,
                totalPrice: $quote->totalCop(),
                status: ReservationStatus::PENDING_PAYMENT,
                paymentMethodId: $gatewayResult->paymentMethodId ?? $paymentMethodId,
                mercadopagoPaymentId: $gatewayResult->paymentId,
                paymentStatus: $gatewayResult->status,
                paymentDetail: $gatewayResult->statusDetail,
                lang: $lang,
                createdAt: new DateTimeImmutable()
            );

            $this->repository->save($reservation);

            $this->recordAuditLog(
                action: 'reservation_created_pending',
                uid: $uid,
                payloadBefore: null,
                payloadAfter: [
                    'reservation_uid' => $uid,
                    'property_id' => $propertyId,
                    'check_in' => $request->checkIn,
                    'check_out' => $request->checkOut,
                    'total_price' => $quote->totalCop(),
                    'status' => 'pending_payment',
                    'payment_id' => $gatewayResult->paymentId,
                    'payment_status' => $gatewayResult->status,
                ],
                clientIp: $request->clientIp
            );

            $this->recordIdempotency($effectiveIdempotencyKey, (string) ($gatewayResult->paymentId ?? $uid));
            if ($effectiveIdempotencyKey !== $uid) {
                $this->recordIdempotency($uid, (string) ($gatewayResult->paymentId ?? $uid));
            }

            // Render and send pending payment instructions emails
            $this->dispatchPendingEmails($reservation, $quote, $gatewayResult);

            $extra = [
                'payment_id' => $gatewayResult->paymentId,
                'status' => $gatewayResult->status,
            ];

            if ($gatewayResult->externalResourceUrl !== null && $gatewayResult->externalResourceUrl !== '') {
                $extra['external_resource_url'] = $gatewayResult->externalResourceUrl;
                if (strtolower($paymentMethodId) === 'efecty') {
                    $extra['printable_voucher_url'] = $gatewayResult->externalResourceUrl;
                }
            }

            if ($gatewayResult->barcode !== null && $gatewayResult->barcode !== '') {
                $extra['barcode'] = $gatewayResult->barcode;
            }

            if ($gatewayResult->verificationCode !== null && $gatewayResult->verificationCode !== '') {
                $extra['verification_code'] = $gatewayResult->verificationCode;
            }

            return BookingPaymentResult::pendingPayment(
                reservationUid: $uid,
                message: 'Payment request processed successfully',
                extra: $extra
            );
        }

        return BookingPaymentResult::error(
            'Unhandled gateway transaction status: ' . $gatewayResult->status,
            500
        );
    }

    /**
     * Looks up existing processed payment by idempotency key.
     */
    private function findExistingIdempotency(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT payment_id FROM payment_idempotency WHERE idempotency_key = :key LIMIT 1');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && isset($row['payment_id'])) {
                return (string) $row['payment_id'];
            }
        } catch (Throwable) {
            // Silently tolerate database errors or unmigrated environments
        }

        return null;
    }

    /**
     * Records an entry into the payment idempotency table.
     */
    private function recordIdempotency(string $key, string $paymentId): void
    {
        if (trim($key) === '') {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('
                INSERT INTO payment_idempotency (idempotency_key, payment_id)
                VALUES (:key, :pay_id)
            ');
            $stmt->execute([
                'key' => trim($key),
                'pay_id' => trim($paymentId),
            ]);
        } catch (Throwable) {
            // Silently tolerate duplicates or schema differences
        }
    }

    /**
     * Records administrative audit log if table is available.
     *
     * @param string $action
     * @param string $uid
     * @param array<string, mixed>|null $payloadBefore
     * @param array<string, mixed>|null $payloadAfter
     * @param string|null $clientIp
     */
    private function recordAuditLog(
        string $action,
        string $uid,
        ?array $payloadBefore,
        ?array $payloadAfter,
        ?string $clientIp
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
                'action' => $action,
                'entity_id' => $uid,
                'payload_before' => $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_SLASHES) : null,
                'payload_after' => $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_SLASHES) : null,
                'ip_address' => $clientIp ?? '127.0.0.1',
            ]);
        } catch (Throwable) {
            // Silently tolerate environments without admin_audit_logs
        }
    }

    /**
     * Dispatches localized pending payment instructions to guest and host.
     */
    private function dispatchPendingEmails(Reservation $reservation, Quote $quote, PaymentGatewayResult $gatewayResult): void
    {
        $lang = $reservation->lang;
        $htmlMessage = $this->renderPendingEmailHtml($reservation, $quote, $gatewayResult);

        // 1. Host Notification Email
        $subjectHost = sprintf(
            'BOOKING INQUIRY: Prop %s (%s) - [%s]',
            $reservation->propertyId,
            $reservation->guestName,
            strtoupper($lang)
        );
        $this->emailSender->send($this->hostNotificationEmail, $subjectHost, $htmlMessage);

        // 2. Primary Guest Email
        $guestTpl = $this->trans(
            $lang,
            'booking',
            'email_subject_guest',
            'We received your booking inquiry - OceanViewFlats %s'
        );
        $subjectGuest = sprintf($guestTpl, $reservation->propertyId);
        $this->emailSender->send($reservation->guestEmail, $subjectGuest, $htmlMessage);
    }

    /**
     * Renders localized HTML email for pending hold notifications.
     */
    private function renderPendingEmailHtml(Reservation $reservation, Quote $quote, PaymentGatewayResult $gatewayResult): string
    {
        $lang = $reservation->lang;
        $copFormatter = '$ ' . number_format($reservation->totalPrice, 0, ',', '.') . ' COP';
        $safeGuestName = htmlspecialchars($reservation->guestName, ENT_QUOTES, 'UTF-8');
        $safePropertyId = htmlspecialchars($reservation->propertyId, ENT_QUOTES, 'UTF-8');
        $safeUid = htmlspecialchars($reservation->reservationUid, ENT_QUOTES, 'UTF-8');
        $safeGuestPhone = htmlspecialchars($reservation->guestPhone, ENT_QUOTES, 'UTF-8');
        $checkInStr = htmlspecialchars($reservation->checkIn, ENT_QUOTES, 'UTF-8');
        $checkOutStr = htmlspecialchars($reservation->checkOut, ENT_QUOTES, 'UTF-8');
        $nightsCount = $quote->nightsCount();

        $emailTitle = $this->trans($lang, 'booking', 'email_title', 'Booking Inquiry');
        $emailReceived = $this->trans(
            $lang,
            'booking',
            'email_received',
            'We have successfully received your direct booking inquiry and placed a temporary hold. Here is your stay summary:'
        );
        $emailSummary = $this->trans($lang, 'booking', 'email_summary', 'Stay Summary');
        $emailProperty = $this->trans($lang, 'booking', 'email_property', 'Property');
        $emailCode = $this->trans($lang, 'booking', 'email_code', 'Reservation Code');
        $emailTotal = $this->trans($lang, 'booking', 'email_total', 'Total');
        $emailFooter = $this->trans(
            $lang,
            'booking',
            'email_footer',
            'We will contact you shortly to confirm receipt of payment.'
        );

        $voucherHtml = '';
        if ($gatewayResult->verificationCode !== null && $gatewayResult->verificationCode !== '') {
            $safeCode = htmlspecialchars($gatewayResult->verificationCode, ENT_QUOTES, 'UTF-8');
            $voucherHtml .= "<div class='item-row'><span>Verification Code</span><strong>{$safeCode}</strong></div>";
        }
        if ($gatewayResult->barcode !== null && $gatewayResult->barcode !== '') {
            $safeBarcode = htmlspecialchars($gatewayResult->barcode, ENT_QUOTES, 'UTF-8');
            $voucherHtml .= "<div class='item-row'><span>Barcode / Reference</span><strong>{$safeBarcode}</strong></div>";
        }
        if ($gatewayResult->externalResourceUrl !== null && $gatewayResult->externalResourceUrl !== '') {
            $safeUrl = htmlspecialchars($gatewayResult->externalResourceUrl, ENT_QUOTES, 'UTF-8');
            $voucherHtml .= "<div class='item-row'><span>Voucher / Link</span><strong><a href='{$safeUrl}' target='_blank'>View / Complete Payment</a></strong></div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <style>
    body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; }
    .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .header { background-color: #0f172a; padding: 32px; text-align: center; }
    .header h1 { color: #ffffff; font-size: 24px; margin: 0; font-weight: 800; letter-spacing: -0.5px; }
    .content { padding: 32px; }
    .summary-card { background-color: #f1f5f9; padding: 24px; border-radius: 12px; margin-bottom: 24px; }
    .summary-title { font-weight: 700; font-size: 14px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 16px; }
    .item-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 15px; }
    .item-row:last-child { border-bottom: none; }
    .total-row { display: flex; justify-content: space-between; padding-top: 16px; margin-top: 16px; border-top: 2px solid #cbd5e1; font-size: 18px; font-weight: 800; }
    .footer { text-align: center; padding: 32px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 13px; color: #64748b; }
    .footer-note { font-size: 14px; color: #475569; margin-top: 20px; }
  </style>
</head>
<body>
  <div class='container'>
    <div class='header'>
      <h1>{$emailTitle}</h1>
    </div>
    <div class='content'>
      <p>Dear {$safeGuestName},</p>
      <p>{$emailReceived}</p>
      
      <div class='summary-card'>
        <div class='summary-title'>{$emailSummary}</div>
        <div class='item-row'><span>{$emailProperty}</span><strong>Apto {$safePropertyId}</strong></div>
        <div class='item-row'><span>{$emailCode}</span><strong>{$safeUid}</strong></div>
        <div class='item-row'><span>Stay Duration</span><strong>{$nightsCount} nights ({$checkInStr} / {$checkOutStr})</strong></div>
        <div class='item-row'><span>Guest Phone</span><strong>{$safeGuestPhone}</strong></div>
        {$voucherHtml}
        <div class='total-row'><span>{$emailTotal}</span><strong>{$copFormatter}</strong></div>
      </div>
      <p class='footer-note'>{$emailFooter}</p>
    </div>
    <div class='footer'>
      &copy; 2026 OceanViewFlats. All rights reserved.
    </div>
  </div>
</body>
</html>
HTML;
    }

    /**
     * Resolves translation string with graceful fallback.
     */
    private function trans(string $lang, string $section, string $key, string $default): string
    {
        $langKey = strtolower($lang);
        if (isset($this->translations[$langKey][$section][$key])) {
            return (string) $this->translations[$langKey][$section][$key];
        }
        if (isset($this->translations['en'][$section][$key])) {
            return (string) $this->translations['en'][$section][$key];
        }
        return $default;
    }
}
