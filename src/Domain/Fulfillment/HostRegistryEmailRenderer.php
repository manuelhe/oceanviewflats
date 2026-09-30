<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use DateTimeInterface;

/**
 * Renders plain-text and HTML guest registry reports dispatched to host staff and condominium reception.
 */
final class HostRegistryEmailRenderer implements HostRegistryEmailRendererInterface
{
    public function __construct(
        private readonly ?DateTimeInterface $fixedTimestamp = null
    ) {}

    public function renderSubject(GuestRegistrySubmission $submission): string
    {
        $property = trim($submission->propertyId);
        $propStr = $property !== '' ? "OceanViewFlats {$property}" : 'Unspecified';
        return $this->stripNewlines("OceanViewFlats Guest Registry Report - {$propStr}");
    }

    public function renderPlainText(
        GuestRegistrySubmission $submission,
        string $doorCode,
        bool $spreadsheetSuccess = true,
        ?string $spreadsheetError = null
    ): string {
        $property = trim($submission->propertyId);
        $propertyStr = $property !== '' ? "OceanViewFlats {$property}" : 'Not specified';
        $checkInStr = trim($submission->checkIn) !== '' ? trim($submission->checkIn) : 'Not specified';
        $checkOutStr = trim($submission->checkOut) !== '' ? trim($submission->checkOut) : 'Not specified';
        $primary = $submission->getPrimaryOccupant();
        $primaryGuestDoc = $primary !== null ? $primary->docNum : 'N/A';

        $sheetStatus = $this->resolveSpreadsheetStatus($spreadsheetSuccess, $spreadsheetError);
        $timestamp = $this->getTimestamp();

        $emailBody = "OceanViewFlats Official Guest Registry Report\n";
        $emailBody .= "==================================================\n\n";
        $emailBody .= "STAY INFORMATION\n";
        $emailBody .= "--------------------------------------------------\n";
        $emailBody .= "Property:      " . $propertyStr . "\n";
        $emailBody .= "Check-in:      " . $checkInStr . "\n";
        $emailBody .= "Check-out:     " . $checkOutStr . "\n";
        $emailBody .= "Total Guests:  " . $submission->getGuestCount() . "\n\n";

        if ($this->hasVehicleInfo($submission)) {
            $emailBody .= "VEHICLE INFORMATION (OPTIONAL)\n";
            $emailBody .= "--------------------------------------------------\n";
            $emailBody .= "Plates:        " . ($submission->carPlates ?: 'None') . "\n";
            $emailBody .= "Make & Model:  " . ($submission->carModel ?: 'None') . "\n\n";
        }

        $emailBody .= "REGISTERED GUESTS DETAILS\n";
        $emailBody .= "--------------------------------------------------\n";
        foreach ($submission->occupants as $g) {
            $emailBody .= "Guest #" . $g->index . ":\n";
            $emailBody .= "  Name:     " . $this->stripNewlines($g->name) . "\n";
            $emailBody .= "  ID/Doc:   " . $this->stripNewlines($g->docType) . " (" . $this->stripNewlines($g->docNum) . ")\n";
            $emailBody .= "  Age:      " . $g->age . "\n";
            $emailBody .= "--------------------------------------------------\n";
        }

        $emailBody .= "\nSMART LOCK ACCESS PIN (ACTION REQUIRED)\n";
        $emailBody .= "--------------------------------------------------\n";
        $emailBody .= "Generated Door PIN:  " . $this->stripNewlines($doorCode) . "\n";
        $emailBody .= "Primary Guest Doc:   " . $this->stripNewlines($primaryGuestDoc) . "\n";
        $emailBody .= "Lock Instructions:   Program this 7-digit code (ending in #)\n";
        $emailBody .= "                     into the apartment smart lock companion app.\n";

        $emailBody .= "\nSYSTEM LOGS\n";
        $emailBody .= "--------------------------------------------------\n";
        $emailBody .= "Submission IP:  " . ($submission->ipAddress ?: 'Unknown') . "\n";
        $emailBody .= "Timestamp:      " . $timestamp . "\n";
        $emailBody .= "Local Backup:   Logged successfully.\n";
        $emailBody .= "Google Sheet:   " . $sheetStatus . "\n";
        $emailBody .= "==================================================\n";

        return $emailBody;
    }

    public function renderHtml(
        GuestRegistrySubmission $submission,
        string $doorCode,
        bool $spreadsheetSuccess = true,
        ?string $spreadsheetError = null
    ): string {
        $property = trim($submission->propertyId);
        $propertyStr = $property !== '' ? "OceanViewFlats {$property}" : 'Not specified';
        $checkInStr = trim($submission->checkIn) !== '' ? trim($submission->checkIn) : 'Not specified';
        $checkOutStr = trim($submission->checkOut) !== '' ? trim($submission->checkOut) : 'Not specified';
        $primary = $submission->getPrimaryOccupant();
        $primaryGuestDoc = $primary !== null ? $primary->docNum : 'N/A';

        $sheetStatus = $this->resolveSpreadsheetStatus($spreadsheetSuccess, $spreadsheetError);
        $timestamp = $this->getTimestamp();

        $safeProperty = htmlspecialchars($propertyStr, ENT_QUOTES, 'UTF-8');
        $safeCheckIn = htmlspecialchars($checkInStr, ENT_QUOTES, 'UTF-8');
        $safeCheckOut = htmlspecialchars($checkOutStr, ENT_QUOTES, 'UTF-8');
        $safeGuestCount = $submission->getGuestCount();
        $safeDoorCode = htmlspecialchars($this->stripNewlines($doorCode), ENT_QUOTES, 'UTF-8');
        $safePrimaryGuestDoc = htmlspecialchars($this->stripNewlines($primaryGuestDoc), ENT_QUOTES, 'UTF-8');
        $safeIp = htmlspecialchars($submission->ipAddress ?: 'Unknown', ENT_QUOTES, 'UTF-8');
        $safeTimestamp = htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8');
        $safeSheetStatus = htmlspecialchars($sheetStatus, ENT_QUOTES, 'UTF-8');

        $vehicleSection = '';
        if ($this->hasVehicleInfo($submission)) {
            $safePlates = htmlspecialchars($submission->carPlates ?: 'None', ENT_QUOTES, 'UTF-8');
            $safeModel = htmlspecialchars($submission->carModel ?: 'None', ENT_QUOTES, 'UTF-8');
            $vehicleSection = <<<HTML
      <div class="card">
        <div class="card-title">Vehicle Information</div>
        <div class="row"><span>Plates</span><strong>{$safePlates}</strong></div>
        <div class="row"><span>Make &amp; Model</span><strong>{$safeModel}</strong></div>
      </div>
HTML;
        }

        $occupantsRows = '';
        foreach ($submission->occupants as $g) {
            $safeName = htmlspecialchars($this->stripNewlines($g->name), ENT_QUOTES, 'UTF-8');
            $safeDocType = htmlspecialchars($this->stripNewlines($g->docType), ENT_QUOTES, 'UTF-8');
            $safeDocNum = htmlspecialchars($this->stripNewlines($g->docNum), ENT_QUOTES, 'UTF-8');
            $safeAge = $g->age;
            $safeIndex = $g->index;

            $occupantsRows .= <<<HTML
        <div class="occupant-item">
          <div class="occupant-header">Guest #{$safeIndex}: <strong>{$safeName}</strong></div>
          <div class="occupant-meta">
            <span>ID/Doc: <code>{$safeDocType} ({$safeDocNum})</code></span>
            <span>Age: {$safeAge}</span>
          </div>
        </div>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 0; line-height: 1.6; }
    .container { max-width: 600px; margin: 32px auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; }
    .header { background-color: #0f172a; padding: 24px; text-align: center; color: #ffffff; }
    .header h2 { margin: 0; color: #ffffff; font-size: 20px; font-weight: 800; }
    .header p { margin: 4px 0 0 0; font-size: 13px; color: #94a3b8; }
    .content { padding: 28px 24px; }
    .card { background-color: #f8fafc; padding: 18px 20px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
    .card-title { font-weight: 700; font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 1px; margin-bottom: 12px; }
    .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #edf2f7; font-size: 14px; }
    .row:last-child { border-bottom: none; }
    .occupant-item { padding: 10px 0; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
    .occupant-item:last-child { border-bottom: none; padding-bottom: 0; }
    .occupant-header { font-size: 14px; margin-bottom: 4px; }
    .occupant-meta { display: flex; justify-content: space-between; font-size: 13px; color: #475569; }
    .pin-box { background-color: #eff6ff; border: 2px solid #0284c7; border-radius: 12px; padding: 20px; margin-bottom: 20px; text-align: center; }
    .pin-box-title { font-weight: 800; font-size: 13px; text-transform: uppercase; color: #0369a1; letter-spacing: 1px; margin-bottom: 8px; }
    .pin-display { font-size: 32px; font-weight: 800; font-family: monospace; letter-spacing: 4px; color: #0284c7; margin: 8px 0; }
    .pin-doc { font-size: 13px; color: #334155; margin-bottom: 8px; }
    .pin-instructions { font-size: 13px; color: #0369a1; background-color: #e0f2fe; padding: 8px 12px; border-radius: 6px; display: inline-block; }
    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <h2>Guest Registry Report</h2>
      <p>OceanViewFlats Operational Reception Brief</p>
    </div>
    <div class="content">
      <div class="card">
        <div class="card-title">Stay Information</div>
        <div class="row"><span>Property</span><strong>{$safeProperty}</strong></div>
        <div class="row"><span>Check-in</span><strong>{$safeCheckIn}</strong></div>
        <div class="row"><span>Check-out</span><strong>{$safeCheckOut}</strong></div>
        <div class="row"><span>Total Guests</span><strong>{$safeGuestCount}</strong></div>
      </div>

      {$vehicleSection}

      <div class="card">
        <div class="card-title">Registered Guests Details</div>
        {$occupantsRows}
      </div>

      <div class="pin-box">
        <div class="pin-box-title">Smart Lock Access PIN (Action Required)</div>
        <div class="pin-display">{$safeDoorCode}</div>
        <div class="pin-doc">Primary Guest Doc: <strong><code>{$safePrimaryGuestDoc}</code></strong></div>
        <div class="pin-instructions">
          Program this 7-digit code (ending in #) into the apartment smart lock companion app.
        </div>
      </div>

      <div class="card">
        <div class="card-title">System Logs</div>
        <div class="row"><span>Submission IP</span><strong>{$safeIp}</strong></div>
        <div class="row"><span>Timestamp</span><strong>{$safeTimestamp}</strong></div>
        <div class="row"><span>Local Backup</span><strong>Logged successfully.</strong></div>
        <div class="row"><span>Google Sheet</span><strong>{$safeSheetStatus}</strong></div>
      </div>
    </div>
    <div class="footer">
      OceanViewFlats Property Management &bull; Reception Registry Dispatch
    </div>
  </div>
</body>
</html>
HTML;
    }

    private function stripNewlines(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }

    private function hasVehicleInfo(GuestRegistrySubmission $submission): bool
    {
        return ($submission->carPlates !== null && $submission->carPlates !== '')
            || ($submission->carModel !== null && $submission->carModel !== '');
    }

    private function resolveSpreadsheetStatus(bool $success, ?string $error): string
    {
        if ($success) {
            return 'Recorded successfully.';
        }

        if ($error !== null && $error !== '') {
            $upper = strtoupper($error);
            if (str_starts_with($upper, 'NOT CONFIGURED')) {
                return 'Not configured.';
            }
            if (str_starts_with($upper, 'FAILED')) {
                return $error;
            }
            return "FAILED ({$error})";
        }

        return 'Not configured.';
    }

    private function getTimestamp(): string
    {
        if ($this->fixedTimestamp !== null) {
            return $this->fixedTimestamp->format('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s');
    }
}
