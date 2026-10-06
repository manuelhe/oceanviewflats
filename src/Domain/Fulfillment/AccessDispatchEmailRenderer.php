<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;
use OceanViewFlats\Domain\Support\PathResolver;

final class AccessDispatchEmailRenderer implements AccessDispatchEmailRendererInterface
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $translations;

    /**
     * @param array<string, array<string, mixed>>|null $translations
     */
    public function __construct(
        private readonly string $baseUrl = 'https://www.oceanviewflats.com',
        ?array $translations = null
    ) {
        $this->translations = $translations ?? PathResolver::loadTranslations();
    }

    public function renderSubject(Reservation $reservation, ?string $lang = null): string
    {
        $resolvedLang = $this->resolveLanguage($reservation, $lang);
        $t = $this->getTranslationsForLang($resolvedLang);

        /** @var string $subjectFormat */
        $subjectFormat = $t['subject'] ?? 'Access Credentials & Arrival Guide - OceanViewFlats %s';

        return sprintf($subjectFormat, $reservation->propertyId);
    }

    public function renderPlainText(
        Reservation $reservation,
        string $doorCode,
        ?string $guideUrl = null,
        ?string $lang = null,
        ?string $recipientName = null
    ): string {
        $resolvedLang = $this->resolveLanguage($reservation, $lang);
        $t = $this->getTranslationsForLang($resolvedLang);
        $resolvedGuideUrl = $guideUrl ?? $this->buildGuideUrl($reservation, $resolvedLang);
        $guestName = $recipientName ?? ($reservation->guestName !== '' ? $reservation->guestName : 'Guest');

        /** @var string $introFormat */
        $introFormat = $t['intro_plain'] ?? 'Dear %s, your Guest Registry has been verified and registered with condominium security.';
        $introText = sprintf($introFormat, $guestName);

        /** @var list<string> $houseRules */
        $houseRules = $t['house_rules'] ?? [];
        $rulesFormatted = '';
        foreach ($houseRules as $rule) {
            $rulesFormatted .= '• ' . $rule . "\n";
        }

        $propertyLabel = (string) ($t['property'] ?? 'Property');
        $codeLabel = (string) ($t['reservation_code'] ?? 'Reservation Code');
        $checkInLabel = (string) ($t['check_in'] ?? 'Check-In');
        $checkInTime = (string) ($t['check_in_time'] ?? 'From 3:00 PM');
        $checkOutLabel = (string) ($t['check_out'] ?? 'Check-Out');
        $checkOutTime = (string) ($t['check_out_time'] ?? 'Until 11:00 AM');
        $summaryTitle = (string) ($t['summary_title'] ?? 'Stay Summary');
        $pinTitle = (string) ($t['pin_title'] ?? 'Smart Lock Door PIN');
        $pinInstructions = (string) ($t['pin_instructions'] ?? 'Enter this 7-digit PIN followed by the # key on the digital door lock to unlock.');
        $guideTitle = (string) ($t['guide_title'] ?? 'Interactive Guest Guide');
        $guideDesc = (string) ($t['guide_desc'] ?? 'Access your complete digital guide with Wi-Fi details, building amenities, pool access, and local recommendations:');
        $houseRulesTitle = (string) ($t['house_rules_title'] ?? 'House Rules & Arrival Reminders');
        $footerText = strip_tags((string) ($t['footer'] ?? 'OceanViewFlats • Beachfront Living in Santa Marta, Colombia'));

        return <<<TEXT
================================================================================
OceanViewFlats - {$t['title']}
================================================================================

{$introText}

--------------------------------------------------------------------------------
{$summaryTitle}
--------------------------------------------------------------------------------
{$propertyLabel}:         OceanViewFlats {$reservation->propertyId}
{$codeLabel}: {$reservation->reservationUid}
{$checkInLabel}:         {$reservation->checkIn} ({$checkInTime})
{$checkOutLabel}:        {$reservation->checkOut} ({$checkOutTime})

--------------------------------------------------------------------------------
{$pinTitle}
--------------------------------------------------------------------------------
DOOR PIN: {$doorCode}
{$pinInstructions}

--------------------------------------------------------------------------------
{$guideTitle}
--------------------------------------------------------------------------------
{$guideDesc}
{$resolvedGuideUrl}

--------------------------------------------------------------------------------
{$houseRulesTitle}
--------------------------------------------------------------------------------
{$rulesFormatted}
================================================================================
{$footerText}
================================================================================
TEXT;
    }

    public function renderHtml(
        Reservation $reservation,
        string $doorCode,
        ?string $guideUrl = null,
        ?string $lang = null,
        ?string $recipientName = null
    ): string {
        $resolvedLang = $this->resolveLanguage($reservation, $lang);
        $t = $this->getTranslationsForLang($resolvedLang);
        $resolvedGuideUrl = $guideUrl ?? $this->buildGuideUrl($reservation, $resolvedLang);
        $guestName = $recipientName ?? ($reservation->guestName !== '' ? $reservation->guestName : 'Guest');

        $safeGuestName = htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8');
        $safePropertyId = htmlspecialchars($reservation->propertyId, ENT_QUOTES, 'UTF-8');
        $safeUid = htmlspecialchars($reservation->reservationUid, ENT_QUOTES, 'UTF-8');
        $safeCheckIn = htmlspecialchars($reservation->checkIn, ENT_QUOTES, 'UTF-8');
        $safeCheckOut = htmlspecialchars($reservation->checkOut, ENT_QUOTES, 'UTF-8');
        $safeDoorCode = htmlspecialchars($doorCode, ENT_QUOTES, 'UTF-8');
        $safeGuideUrl = htmlspecialchars($resolvedGuideUrl, ENT_QUOTES, 'UTF-8');

        /** @var string $introFormat */
        $introFormat = $t['intro'] ?? 'Dear <strong>%s</strong>, your Guest Registry has been verified and registered with condominium security.';
        $introHtml = sprintf($introFormat, $safeGuestName);

        /** @var list<string> $houseRules */
        $houseRules = $t['house_rules'] ?? [];
        $rulesListHtml = '';
        foreach ($houseRules as $rule) {
            $rulesListHtml .= '<li style="margin-bottom: 8px;">' . htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        $title = (string) ($t['title'] ?? 'Your Access Credentials & Arrival Guide');
        $summaryTitle = (string) ($t['summary_title'] ?? 'Stay Summary');
        $propertyLabel = (string) ($t['property'] ?? 'Property');
        $codeLabel = (string) ($t['reservation_code'] ?? 'Reservation Code');
        $checkInLabel = (string) ($t['check_in'] ?? 'Check-In');
        $checkInTime = (string) ($t['check_in_time'] ?? 'From 3:00 PM');
        $checkOutLabel = (string) ($t['check_out'] ?? 'Check-Out');
        $checkOutTime = (string) ($t['check_out_time'] ?? 'Until 11:00 AM');
        $pinTitle = (string) ($t['pin_title'] ?? 'Smart Lock Door PIN');
        $pinInstructions = (string) ($t['pin_instructions'] ?? 'Enter this 7-digit PIN followed by the # key on the digital door lock to unlock.');
        $guideTitle = (string) ($t['guide_title'] ?? 'Interactive Guest Guide');
        $guideDesc = (string) ($t['guide_desc'] ?? 'Access your complete digital guide with Wi-Fi details, building amenities, pool access, and local recommendations:');
        $btnGuide = (string) ($t['btn_guide'] ?? 'Open Guest Guide');
        $houseRulesTitle = (string) ($t['house_rules_title'] ?? 'House Rules & Arrival Reminders');
        $footerHtml = (string) ($t['footer'] ?? 'OceanViewFlats &bull; Beachfront Living in Santa Marta, Colombia');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.6; }
    .container { max-width: 600px; margin: 32px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; }
    .header { background-color: #0f172a; padding: 28px 24px; text-align: center; }
    .header h1 { color: #ffffff; font-size: 22px; margin: 0 0 6px 0; font-weight: 800; letter-spacing: -0.5px; }
    .header p { color: #94a3b8; font-size: 14px; margin: 0; }
    .content { padding: 28px 24px; }
    .summary-card { background-color: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 24px; border: 1px solid #e2e8f0; }
    .summary-title { font-weight: 700; font-size: 13px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 14px; }
    .item-row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
    .item-row:last-child { border-bottom: none; }
    .pin-box { background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 2px solid #059669; padding: 24px; border-radius: 12px; margin: 24px 0; text-align: center; }
    .pin-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #065f46; margin-bottom: 8px; }
    .pin-code { font-size: 34px; font-weight: 800; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; letter-spacing: 6px; color: #047857; margin: 8px 0; }
    .pin-desc { font-size: 13px; color: #065f46; margin-top: 8px; }
    .guide-card { background-color: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px; padding: 20px; margin: 24px 0; text-align: center; }
    .guide-title { font-size: 16px; font-weight: 700; color: #0369a1; margin-bottom: 6px; }
    .guide-desc { font-size: 13px; color: #0c4a6e; margin-bottom: 18px; }
    .btn { display: inline-block; padding: 14px 28px; background-color: #0284c7; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 15px; box-shadow: 0 4px 6px -1px rgba(2,132,199,0.25); }
    .rules-card { background-color: #fffbeb; border: 1px solid #fef3c7; border-radius: 12px; padding: 20px; margin: 24px 0; }
    .rules-title { font-size: 14px; font-weight: 700; color: #92400e; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
    .rules-list { font-size: 13px; color: #78350f; padding-left: 20px; margin: 0; line-height: 1.6; }
    .footer { text-align: center; padding: 24px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    code { background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-size: 13px; color: #0f172a; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>{$title}</h1>
      <p>OceanViewFlats Santa Marta</p>
    </div>
    <div class="content">
      <p>{$introHtml}</p>

      <div class="summary-card">
        <div class="summary-title">{$summaryTitle}</div>
        <div class="item-row"><span>{$propertyLabel}</span><strong>OceanViewFlats {$safePropertyId}</strong></div>
        <div class="item-row"><span>{$codeLabel}</span><strong><code>{$safeUid}</code></strong></div>
        <div class="item-row"><span>{$checkInLabel}</span><strong>{$safeCheckIn} ({$checkInTime})</strong></div>
        <div class="item-row"><span>{$checkOutLabel}</span><strong>{$safeCheckOut} ({$checkOutTime})</strong></div>
      </div>

      <div class="pin-box">
        <div class="pin-title">{$pinTitle}</div>
        <div class="pin-code">{$safeDoorCode}</div>
        <div class="pin-desc">{$pinInstructions}</div>
      </div>

      <div class="guide-card">
        <div class="guide-title">{$guideTitle}</div>
        <div class="guide-desc">{$guideDesc}</div>
        <div>
          <a href="{$safeGuideUrl}" class="btn">{$btnGuide}</a>
        </div>
      </div>

      <div class="rules-card">
        <div class="rules-title">{$houseRulesTitle}</div>
        <ul class="rules-list">
          {$rulesListHtml}
        </ul>
      </div>
    </div>
    <div class="footer">
      <p>{$footerHtml}</p>
      &copy; 2026 OceanViewFlats. Calle 26 # 2-80, Playa Salguero, Santa Marta, Colombia.
    </div>
  </div>
</body>
</html>
HTML;
    }

    public function buildGuideUrl(Reservation $reservation, ?string $lang = null): string
    {
        $resolvedLang = $this->resolveLanguage($reservation, $lang);
        $params = http_build_query([
            'code' => $reservation->reservationUid,
            'lang' => $resolvedLang,
        ]);

        return rtrim($this->baseUrl, '/') . '/guide/?' . $params;
    }

    private function resolveLanguage(Reservation $reservation, ?string $explicitLang = null): string
    {
        if ($explicitLang !== null && in_array($explicitLang, ['en', 'es', 'fr', 'it', 'de', 'ja'], true)) {
            return $explicitLang;
        }

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

    /**
     * @return array<string, mixed>
     */
    private function getTranslationsForLang(string $lang): array
    {
        /** @var array<string, mixed> $langTranslations */
        $langTranslations = $this->translations[$lang]['access_dispatch']
            ?? $this->translations['en']['access_dispatch']
            ?? [];

        return $langTranslations;
    }
}
