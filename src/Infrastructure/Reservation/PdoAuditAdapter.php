<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation;

use PDO;
use Throwable;
use OceanViewFlats\Domain\Reservation\ActorContext;
use OceanViewFlats\Domain\Reservation\Port\AuditPort;

/**
 * Production PDO adapter for immutable administrative audit logging.
 */
final class PdoAuditAdapter implements AuditPort
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @inheritDoc
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $payloadBefore = null,
        ?array $payloadAfter = null,
        ?ActorContext $actor = null
    ): ?int {
        try {
            $ip = $actor->ipAddress ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $ua = $actor->userAgent ?? (isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null);

            $jsonBefore = $payloadBefore !== null ? json_encode($payloadBefore, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $jsonAfter = $payloadAfter !== null ? json_encode($payloadAfter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $stmt = $this->pdo->prepare('
                INSERT INTO `admin_audit_logs` (
                    `admin_user_id`, `action`, `entity_type`, `entity_id`,
                    `payload_before`, `payload_after`, `ip_address`, `user_agent`, `created_at`
                ) VALUES (
                    :admin_user_id, :action, :entity_type, :entity_id,
                    :payload_before, :payload_after, :ip_address, :user_agent, CURRENT_TIMESTAMP
                )
            ');

            $stmt->execute([
                ':admin_user_id' => $actor?->adminUserId,
                ':action' => $action,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':payload_before' => $jsonBefore,
                ':payload_after' => $jsonAfter,
                ':ip_address' => $ip !== '' ? $ip : '127.0.0.1',
                ':user_agent' => $ua,
            ]);

            $id = $this->pdo->lastInsertId();
            return $id !== false && $id !== '' ? (int) $id : null;
        } catch (Throwable) {
            // Silently tolerate missing audit table or non-fatal logging failure
            return null;
        }
    }
}
