<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

final class GoogleSheetWebhookSync implements SpreadsheetSyncInterface
{
    public function __construct(
        private readonly ?string $webhookUrl = null,
        private readonly int $timeoutSeconds = 8
    ) {}

    public static function createFromEnv(): self
    {
        $url = $_ENV['GOOGLE_SHEET_WEBAPP_URL']
            ?? $_SERVER['GOOGLE_SHEET_WEBAPP_URL']
            ?? getenv('GOOGLE_SHEET_WEBAPP_URL')
            ?: (defined('GOOGLE_SHEET_WEBAPP_URL') ? constant('GOOGLE_SHEET_WEBAPP_URL') : null);

        return new self($url !== null && $url !== '' ? (string)$url : null);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function sync(Reservation $reservation, array $extra = []): bool
    {
        $url = $this->webhookUrl;
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $payload = [
            'timestamp' => date('Y-m-d H:i:s'),
            'reservation_uid' => $reservation->reservationUid,
            'property' => $reservation->propertyId,
            'check_in' => $reservation->checkIn,
            'check_out' => $reservation->checkOut,
            'guest_name' => $reservation->guestName,
            'guest_email' => $reservation->guestEmail,
            'guest_phone' => $reservation->guestPhone,
            'total_price' => $reservation->totalPrice,
            'status' => (string)($extra['status'] ?? $reservation->status->value),
            'payment_id' => (string)($extra['payment_id'] ?? ($reservation->mercadopagoPaymentId ?? '')),
            'payment_status' => (string)($extra['payment_status'] ?? ($reservation->paymentStatus ?? '')),
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: OceanViewFlats Fulfillment Service'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && ($httpCode >= 200 && $httpCode < 400);
    }
}
