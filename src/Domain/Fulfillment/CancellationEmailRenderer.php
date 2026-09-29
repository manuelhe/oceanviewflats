<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\Reservation;

/**
 * Renders localized transactional cancellation notices for guests and operational host alerts.
 * Strictly adheres to guest privacy by withholding internal staff operational reasons from guest emails.
 */
final class CancellationEmailRenderer implements CancellationEmailRendererInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private readonly array $translations;

    /**
     * @param array<string, array<string, mixed>>|null $translations
     */
    public function __construct(
        private readonly string $baseUrl = 'https://www.oceanviewflats.com',
        ?array $translations = null
    ) {
        $this->translations = $translations ?? (require __DIR__ . '/../../../public/api/translations.php');
    }

    public function renderGuestCancellationHtml(Reservation $reservation, float $refundAmount, float $policyRetention): string
    {
        $lang = $this->resolveLanguage($reservation);
        $t = $this->translations[$lang]['cancellation'] ?? $this->translations['en']['cancellation'];

        $nights = $this->calculateNights($reservation->checkIn, $reservation->checkOut);
        $formattedTotal = '$ ' . number_format($reservation->totalPrice, 0, ',', '.') . ' COP';
        $formattedRefund = '$ ' . number_format($refundAmount, 0, ',', '.') . ' COP';
        $formattedRetention = '$ ' . number_format($policyRetention, 0, ',', '.') . ' COP';

        $supportUrl = $this->buildSupportUrl($lang);

        $safeGuestName = htmlspecialchars($reservation->guestName, ENT_QUOTES, 'UTF-8');
        $safePropertyId = htmlspecialchars($reservation->propertyId, ENT_QUOTES, 'UTF-8');
        $safeUid = htmlspecialchars($reservation->reservationUid, ENT_QUOTES, 'UTF-8');
        $safeCheckIn = htmlspecialchars($reservation->checkIn, ENT_QUOTES, 'UTF-8');
        $safeCheckOut = htmlspecialchars($reservation->checkOut, ENT_QUOTES, 'UTF-8');

        $introText = sprintf($t['intro'], $safeGuestName);
        $nightsText = sprintf($t['nights_val'], $nights);

        $isFullRefund = ($refundAmount >= ($reservation->totalPrice - 0.01) && $policyRetention <= 0.01);
        if ($isFullRefund) {
            $refundNote = sprintf($t['refund_full_note'], $formattedRefund);
            $noticeBoxStyle = 'background-color: #f0fdf4; border-left: 4px solid #10b981; color: #166534;';
        } elseif ($refundAmount > 0.0) {
            $refundNote = sprintf($t['refund_partial_note'], $formattedRefund, $formattedRetention);
            $noticeBoxStyle = 'background-color: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e;';
        } else {
            $refundNote = sprintf($t['refund_none_note'], $formattedTotal);
            $noticeBoxStyle = 'background-color: #f8fafc; border-left: 4px solid #64748b; color: #334155;';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.6; }
    .container { max-width: 600px; margin: 32px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .header { background-color: #0f172a; padding: 28px 24px; text-align: center; }
    .header h1 { color: #ffffff; font-size: 22px; margin: 0 0 6px 0; font-weight: 800; letter-spacing: -0.5px; }
    .header p { color: #94a3b8; font-size: 14px; margin: 0; }
    .content { padding: 28px 24px; }
    .status-badge { display: inline-block; background-color: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    .summary-card { background-color: #f8fafc; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid #e2e8f0; }
    .summary-title { font-weight: 700; font-size: 13px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 14px; }
    .item-row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
    .item-row:last-child { border-bottom: none; }
    .financial-divider { padding-top: 14px; margin-top: 14px; border-top: 2px solid #cbd5e1; }
    .refund-row { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
    .refund-val { color: #059669; font-weight: 700; }
    .retention-val { color: #64748b; font-weight: 600; }
    .total-val { font-weight: 700; color: #0f172a; }
    .notice-box { padding: 14px 16px; border-radius: 6px; margin: 20px 0; font-size: 13px; line-height: 1.5; {$noticeBoxStyle} }
    .cta-container { text-align: center; margin: 24px 0; }
    .btn { display: inline-block; padding: 12px 24px; background-color: #0f172a; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px; }
    .support-text { font-size: 13px; color: #64748b; margin-top: 18px; text-align: center; }
    .footer { text-align: center; padding: 24px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    code { background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #0f172a; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>{$t['title']}</h1>
      <p>OceanViewFlats Santa Marta</p>
    </div>
    <div class="content">
      <p>{$introText}</p>
      <p>{$t['desc']}</p>
      
      <div class="summary-card">
        <div class="summary-title">{$t['summary']}</div>
        <div class="item-row"><span>{$t['property']}</span><strong>OceanViewFlats {$safePropertyId}</strong></div>
        <div class="item-row"><span>{$t['code']}</span><strong><code>{$safeUid}</code></strong></div>
        <div class="item-row"><span>{$t['check_in']}</span><strong>{$safeCheckIn}</strong></div>
        <div class="item-row"><span>{$t['check_out']}</span><strong>{$safeCheckOut}</strong></div>
        <div class="item-row"><span>{$t['nights']}</span><strong>{$nightsText}</strong></div>
        <div class="item-row"><span>{$t['status']}</span><span class="status-badge">{$t['status_val']}</span></div>
        
        <div class="financial-divider">
          <div class="refund-row"><span>{$t['total_price']}</span><span class="total-val">{$formattedTotal}</span></div>
          <div class="refund-row"><span>{$t['refund_amount']}</span><span class="refund-val">{$formattedRefund}</span></div>
          <div class="refund-row"><span>{$t['policy_retention']}</span><span class="retention-val">{$formattedRetention}</span></div>
        </div>
      </div>

      <div class="notice-box">
        {$refundNote}
      </div>

      <p class="support-text">{$t['support_intro']}</p>

      <div class="cta-container">
        <a href="{$supportUrl}" class="btn">{$t['btn_support']}</a>
      </div>
    </div>
    <div class="footer">
      {$t['footer']}<br>&copy; 2026 OceanViewFlats. Calle 26 # 2-80, Playa Salguero, Santa Marta, Colombia.
    </div>
  </div>
</body>
</html>
HTML;
    }

    public function renderGuestSubject(Reservation $reservation): string
    {
        $lang = $this->resolveLanguage($reservation);
        $t = $this->translations[$lang]['cancellation'] ?? $this->translations['en']['cancellation'];

        return sprintf($t['subject'], $reservation->propertyId);
    }

    public function renderHostNotificationHtml(Reservation $reservation, float $refundAmount, float $policyRetention, string $reason): string
    {
        $lang = $this->resolveLanguage($reservation);
        $nights = $this->calculateNights($reservation->checkIn, $reservation->checkOut);
        $formattedTotal = '$ ' . number_format($reservation->totalPrice, 0, ',', '.') . ' COP';
        $formattedRefund = '$ ' . number_format($refundAmount, 0, ',', '.') . ' COP';
        $formattedRetention = '$ ' . number_format($policyRetention, 0, ',', '.') . ' COP';

        $safeGuestName = htmlspecialchars($reservation->guestName, ENT_QUOTES, 'UTF-8');
        $safeGuestEmail = htmlspecialchars($reservation->guestEmail, ENT_QUOTES, 'UTF-8');
        $safeGuestPhone = htmlspecialchars($reservation->guestPhone, ENT_QUOTES, 'UTF-8');
        $safePropertyId = htmlspecialchars($reservation->propertyId, ENT_QUOTES, 'UTF-8');
        $safeUid = htmlspecialchars($reservation->reservationUid, ENT_QUOTES, 'UTF-8');
        $safeCheckIn = htmlspecialchars($reservation->checkIn, ENT_QUOTES, 'UTF-8');
        $safeCheckOut = htmlspecialchars($reservation->checkOut, ENT_QUOTES, 'UTF-8');
        $safeReason = htmlspecialchars($reason !== '' ? $reason : 'N/A', ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.6; }
    .container { max-width: 600px; margin: 32px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; }
    .header { background-color: #991b1b; padding: 24px; text-align: center; color: #ffffff; }
    .content { padding: 28px 24px; }
    .card { background-color: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #edf2f7; font-size: 14px; }
    .row:last-child { border-bottom: none; }
    .reason-box { background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #991b1b; margin: 16px 0; }
    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
    code { background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #0f172a; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h2 style="margin:0;color:#ffffff;">Reservation Cancelled</h2>
      <p style="margin:4px 0 0 0;font-size:13px;opacity:0.9;">OceanViewFlats Operational Notification</p>
    </div>
    <div class="content">
      <p>A reservation has been cancelled in the system.</p>
      
      <div class="reason-box">
        <strong>Cancellation Reason:</strong> {$safeReason}
      </div>

      <div class="card">
        <div class="row"><span>Property</span><strong>OceanViewFlats {$safePropertyId}</strong></div>
        <div class="row"><span>Reservation Code</span><strong><code>{$safeUid}</code></strong></div>
        <div class="row"><span>Primary Guest</span><strong>{$safeGuestName}</strong></div>
        <div class="row"><span>Guest Email</span><strong>{$safeGuestEmail}</strong></div>
        <div class="row"><span>Guest Phone</span><strong>{$safeGuestPhone}</strong></div>
        <div class="row"><span>Check-In</span><strong>{$safeCheckIn}</strong></div>
        <div class="row"><span>Check-Out</span><strong>{$safeCheckOut}</strong></div>
        <div class="row"><span>Nights</span><strong>{$nights} nights</strong></div>
        <div class="row"><span>Total Booking</span><strong>{$formattedTotal}</strong></div>
        <div class="row"><span>Refunded Amount</span><strong style="color:#059669;">{$formattedRefund}</strong></div>
        <div class="row"><span>Policy Retention</span><strong style="color:#64748b;">{$formattedRetention}</strong></div>
        <div class="row"><span>Guest Language</span><strong>{$lang}</strong></div>
      </div>
    </div>
    <div class="footer">
      OceanViewFlats Automated Fulfillment Dispatch
    </div>
  </div>
</body>
</html>
HTML;
    }

    public function renderHostSubject(Reservation $reservation): string
    {
        $lang = strtoupper($this->resolveLanguage($reservation));
        return "[ADMIN] Reservation Cancelled: Prop {$reservation->propertyId} ({$reservation->guestName}) - [{$lang}]";
    }

    public function buildSupportUrl(?string $lang = null): string
    {
        $lang = $lang ?? 'en';
        if ($lang === 'en') {
            return rtrim($this->baseUrl, '/') . '/contact';
        }
        return rtrim($this->baseUrl, '/') . '/contact/' . $lang . '.html';
    }

    private function resolveLanguage(Reservation $reservation): string
    {
        $lang = trim($reservation->lang);

        if (in_array($lang, ['en', 'es', 'fr', 'it', 'de', 'ja'], true)) {
            return $lang;
        }

        $email = strtolower($reservation->guestEmail);
        foreach (['.cl', '.ar', '.co', '.mx', '.es', '.pe', '.ec', '.uy'] as $tld) {
            if (str_ends_with($email, $tld) || str_contains($email, $tld . '/')) {
                return 'es';
            }
        }

        return 'en';
    }

    private function calculateNights(string $checkIn, string $checkOut): int
    {
        try {
            $in = new DateTimeImmutable($checkIn);
            $out = new DateTimeImmutable($checkOut);
            return max(1, (int)$in->diff($out)->days);
        } catch (\Throwable) {
            return 1;
        }
    }
}
