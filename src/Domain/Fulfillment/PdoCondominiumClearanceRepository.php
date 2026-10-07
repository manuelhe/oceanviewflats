<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use PDO;

/**
 * PDO-backed database repository for CondominiumClearance entities.
 * Compatible with MySQL and SQLite.
 */
final class PdoCondominiumClearanceRepository implements CondominiumClearanceRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function save(CondominiumClearance $clearance): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $payloadJson = $clearance->requestPayload !== null
            ? json_encode($clearance->requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $now = date('Y-m-d H:i:s');
        $params = [
            ':reservation_uid' => $clearance->reservationUid,
            ':property_id' => $clearance->propertyId,
            ':status' => $clearance->status,
            ':clearance_number' => $clearance->clearanceNumber,
            ':error_message' => $clearance->errorMessage,
            ':request_payload' => $payloadJson,
            ':attempts' => $clearance->attempts,
            ':last_attempt_at' => $clearance->lastAttemptAt ?? $now,
            ':synced_at' => $clearance->syncedAt,
            ':created_at' => $clearance->createdAt ?? $now,
            ':updated_at' => $clearance->updatedAt ?? $now,
        ];

        if ($driver === 'sqlite') {
            $sql = '
                INSERT INTO condominium_clearances (
                    reservation_uid,
                    property_id,
                    status,
                    clearance_number,
                    error_message,
                    request_payload,
                    attempts,
                    last_attempt_at,
                    synced_at,
                    created_at,
                    updated_at
                ) VALUES (
                    :reservation_uid,
                    :property_id,
                    :status,
                    :clearance_number,
                    :error_message,
                    :request_payload,
                    :attempts,
                    :last_attempt_at,
                    :synced_at,
                    :created_at,
                    :updated_at
                )
                ON CONFLICT(reservation_uid) DO UPDATE SET
                    property_id = excluded.property_id,
                    status = excluded.status,
                    clearance_number = excluded.clearance_number,
                    error_message = excluded.error_message,
                    request_payload = excluded.request_payload,
                    attempts = excluded.attempts,
                    last_attempt_at = excluded.last_attempt_at,
                    synced_at = excluded.synced_at,
                    updated_at = excluded.updated_at
            ';
        } else {
            $sql = '
                INSERT INTO `condominium_clearances` (
                    `reservation_uid`,
                    `property_id`,
                    `status`,
                    `clearance_number`,
                    `error_message`,
                    `request_payload`,
                    `attempts`,
                    `last_attempt_at`,
                    `synced_at`,
                    `created_at`,
                    `updated_at`
                ) VALUES (
                    :reservation_uid,
                    :property_id,
                    :status,
                    :clearance_number,
                    :error_message,
                    :request_payload,
                    :attempts,
                    :last_attempt_at,
                    :synced_at,
                    :created_at,
                    :updated_at
                )
                ON DUPLICATE KEY UPDATE
                    `property_id` = VALUES(`property_id`),
                    `status` = VALUES(`status`),
                    `clearance_number` = VALUES(`clearance_number`),
                    `error_message` = VALUES(`error_message`),
                    `request_payload` = VALUES(`request_payload`),
                    `attempts` = VALUES(`attempts`),
                    `last_attempt_at` = VALUES(`last_attempt_at`),
                    `synced_at` = VALUES(`synced_at`),
                    `updated_at` = VALUES(`updated_at`)
            ';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function findByReservationUid(string $reservationUid): ?CondominiumClearance
    {
        $stmt = $this->pdo->prepare('SELECT * FROM condominium_clearances WHERE reservation_uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $reservationUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return CondominiumClearance::fromArray($row);
    }

    /**
     * @return list<CondominiumClearance>
     */
    public function findFailedClearances(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM condominium_clearances WHERE status = 'failed' ORDER BY last_attempt_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        foreach ($rows as $row) {
            $results[] = CondominiumClearance::fromArray($row);
        }

        return $results;
    }
}
