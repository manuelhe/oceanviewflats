<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * Production MySQL PDO storage adapter for Reservation persistence.
 * Implements authoritative hold windows and date range queries with prepared statements.
 */
final class PdoReservationRepository implements ReservationRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function save(Reservation $reservation): Reservation
    {
        $sql = "INSERT INTO `reservations` (
            `reservation_uid`,
            `property_id`,
            `guest_name`,
            `guest_email`,
            `guest_phone`,
            `check_in`,
            `check_out`,
            `total_price`,
            `status`,
            `payment_method_id`,
            `mercadopago_preference_id`,
            `mercadopago_payment_id`,
            `payment_status`,
            `payment_detail`,
            `lang`
        ) VALUES (
            :reservation_uid,
            :property_id,
            :guest_name,
            :guest_email,
            :guest_phone,
            :check_in,
            :check_out,
            :total_price,
            :status,
            :payment_method_id,
            :mercadopago_preference_id,
            :mercadopago_payment_id,
            :payment_status,
            :payment_detail,
            :lang
        ) ON DUPLICATE KEY UPDATE
            `guest_name` = VALUES(`guest_name`),
            `guest_email` = VALUES(`guest_email`),
            `guest_phone` = VALUES(`guest_phone`),
            `total_price` = VALUES(`total_price`),
            `status` = VALUES(`status`),
            `payment_method_id` = VALUES(`payment_method_id`),
            `mercadopago_preference_id` = VALUES(`mercadopago_preference_id`),
            `mercadopago_payment_id` = VALUES(`mercadopago_payment_id`),
            `payment_status` = VALUES(`payment_status`),
            `payment_detail` = VALUES(`payment_detail`),
            `lang` = VALUES(`lang`)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':reservation_uid' => $reservation->reservationUid,
            ':property_id' => $reservation->propertyId,
            ':guest_name' => $reservation->guestName,
            ':guest_email' => $reservation->guestEmail,
            ':guest_phone' => $reservation->guestPhone,
            ':check_in' => $reservation->checkIn,
            ':check_out' => $reservation->checkOut,
            ':total_price' => $reservation->totalPrice,
            ':status' => $reservation->status->value,
            ':payment_method_id' => $reservation->paymentMethodId,
            ':mercadopago_preference_id' => $reservation->mercadopagoPreferenceId,
            ':mercadopago_payment_id' => $reservation->mercadopagoPaymentId,
            ':payment_status' => $reservation->paymentStatus,
            ':payment_detail' => $reservation->paymentDetail,
            ':lang' => $reservation->lang,
        ]);

        return $this->findByUid($reservation->reservationUid) ?? $reservation;
    }

    public function findByUid(string $reservationUid): ?Reservation
    {
        $stmt = $this->pdo->prepare("SELECT * FROM `reservations` WHERE `reservation_uid` = :uid LIMIT 1");
        $stmt->execute([':uid' => $reservationUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->rowToEntity($row);
    }

    public function findActiveByProperty(
        string $propertyId,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array {
        $reference = $now ?? new DateTimeImmutable();
        $standardThreshold = $reference->sub(new DateInterval("PT{$standardHoldMinutes}M"))->format('Y-m-d H:i:s');
        $voucherThreshold = $reference->sub(new DateInterval("PT{$voucherHoldHours}H"))->format('Y-m-d H:i:s');

        $sql = "SELECT * FROM `reservations`
            WHERE `property_id` = :property_id
              AND `status` != 'cancelled'
              AND (
                  `status` = 'confirmed'
                  OR (
                      `status` = 'pending_payment'
                      AND LOWER(COALESCE(`payment_method_id`, '')) = 'efecty'
                      AND `created_at` > :voucher_threshold
                  )
                  OR (
                      `status` = 'pending_payment'
                      AND LOWER(COALESCE(`payment_method_id`, '')) != 'efecty'
                      AND `created_at` > :standard_threshold
                  )
              )
            ORDER BY `check_in` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':property_id' => $propertyId,
            ':voucher_threshold' => $voucherThreshold,
            ':standard_threshold' => $standardThreshold,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reservations = [];
        foreach ($rows as $row) {
            $reservations[] = $this->rowToEntity($row);
        }

        return $reservations;
    }

    public function findOverlappingActive(
        string $propertyId,
        string $checkIn,
        string $checkOut,
        ?DateTimeImmutable $now = null,
        int $standardHoldMinutes = Reservation::DEFAULT_STANDARD_HOLD_MINUTES,
        int $voucherHoldHours = Reservation::DEFAULT_VOUCHER_HOLD_HOURS
    ): array {
        $reference = $now ?? new DateTimeImmutable();
        $standardThreshold = $reference->sub(new DateInterval("PT{$standardHoldMinutes}M"))->format('Y-m-d H:i:s');
        $voucherThreshold = $reference->sub(new DateInterval("PT{$voucherHoldHours}H"))->format('Y-m-d H:i:s');

        $sql = "SELECT * FROM `reservations`
            WHERE `property_id` = :property_id
              AND `status` != 'cancelled'
              AND (
                  `status` = 'confirmed'
                  OR (
                      `status` = 'pending_payment'
                      AND LOWER(COALESCE(`payment_method_id`, '')) = 'efecty'
                      AND `created_at` > :voucher_threshold
                  )
                  OR (
                      `status` = 'pending_payment'
                      AND LOWER(COALESCE(`payment_method_id`, '')) != 'efecty'
                      AND `created_at` > :standard_threshold
                  )
              )
              AND (`check_in` < :check_out AND `check_out` > :check_in)
            ORDER BY `check_in` ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':property_id' => $propertyId,
            ':voucher_threshold' => $voucherThreshold,
            ':standard_threshold' => $standardThreshold,
            ':check_in' => $checkIn,
            ':check_out' => $checkOut,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reservations = [];
        foreach ($rows as $row) {
            $reservations[] = $this->rowToEntity($row);
        }

        return $reservations;
    }

    public function updateStatus(
        string $reservationUid,
        ReservationStatus $status,
        ?string $paymentId = null,
        ?string $paymentStatus = null,
        ?string $paymentDetail = null
    ): ?Reservation {
        $fields = ['`status` = :status'];
        $params = [
            ':uid' => $reservationUid,
            ':status' => $status->value,
        ];

        if ($paymentId !== null) {
            $fields[] = '`mercadopago_payment_id` = :payment_id';
            $params[':payment_id'] = $paymentId;
        }
        if ($paymentStatus !== null) {
            $fields[] = '`payment_status` = :payment_status';
            $params[':payment_status'] = $paymentStatus;
        }
        if ($paymentDetail !== null) {
            $fields[] = '`payment_detail` = :payment_detail';
            $params[':payment_detail'] = $paymentDetail;
        }

        $sql = 'UPDATE `reservations` SET ' . implode(', ', $fields) . ' WHERE `reservation_uid` = :uid';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->findByUid($reservationUid);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToEntity(array $row): Reservation
    {
        return new Reservation(
            reservationUid: (string) $row['reservation_uid'],
            propertyId: (string) $row['property_id'],
            guestName: (string) $row['guest_name'],
            guestEmail: (string) $row['guest_email'],
            guestPhone: (string) $row['guest_phone'],
            checkIn: (string) $row['check_in'],
            checkOut: (string) $row['check_out'],
            totalPrice: (float) $row['total_price'],
            status: ReservationStatus::from((string) $row['status']),
            paymentMethodId: isset($row['payment_method_id']) ? (string) $row['payment_method_id'] : null,
            id: isset($row['id']) ? (int) $row['id'] : null,
            mercadopagoPreferenceId: isset($row['mercadopago_preference_id']) ? (string) $row['mercadopago_preference_id'] : null,
            mercadopagoPaymentId: isset($row['mercadopago_payment_id']) ? (string) $row['mercadopago_payment_id'] : null,
            paymentStatus: isset($row['payment_status']) ? (string) $row['payment_status'] : null,
            paymentDetail: isset($row['payment_detail']) ? (string) $row['payment_detail'] : null,
            lang: isset($row['lang']) ? (string) $row['lang'] : 'en',
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string) $row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string) $row['updated_at']) : null
        );
    }
}
