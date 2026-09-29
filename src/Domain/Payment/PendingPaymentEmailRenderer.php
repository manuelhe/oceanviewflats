<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use OceanViewFlats\Domain\Quote\Quote;
use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Default implementation of PendingPaymentEmailRendererInterface.
 * Produces responsive, localized HTML email templates with cash voucher / PSE clearing details.
 */
final class PendingPaymentEmailRenderer implements PendingPaymentEmailRendererInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private readonly array $translations;

    /**
     * @param array<string, array<string, mixed>>|null $translations
     */
    public function __construct(?array $translations = null)
    {
        if ($translations !== null) {
            $this->translations = $translations;
        } else {
            $transFile = dirname(__DIR__, 3) . '/public/api/translations.php';
            $this->translations = file_exists($transFile) ? (require $transFile) : [];
        }
    }

    /**
     * @inheritDoc
     */
    public function renderPendingEmailHtml(
        Reservation $reservation,
        Quote $quote,
        PaymentGatewayResult $gatewayResult
    ): string {
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
