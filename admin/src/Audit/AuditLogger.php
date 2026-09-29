<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Audit;

use PDO;

/**
 * Service providing immutable administrative audit log tracking per ADR 0005.
 */
final class AuditLogger
{
    private static ?PDO $defaultPdo = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function setDefaultPdo(?PDO $pdo): void
    {
        self::$defaultPdo = $pdo;
    }

    /**
     * Instance method for dependency-injected usage.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $adminUserId = null,
        string $ipAddress = '',
        ?string $userAgent = null
    ): int {
        return self::log(
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            before: $before,
            after: $after,
            adminUserId: $adminUserId,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            pdo: $this->pdo
        );
    }

    /**
     * Records an administrative action to admin_audit_logs.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function log(
        string $action,
        string $entityType,
        string $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $adminUserId = null,
        string $ipAddress = '',
        ?string $userAgent = null,
        ?PDO $pdo = null
    ): int {
        $db = $pdo ?? self::$defaultPdo;
        if ($db === null) {
            throw new \InvalidArgumentException('PDO instance is required to write audit logs.');
        }

        $ip = $ipAddress !== '' ? $ipAddress : (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $ua = $userAgent ?? (isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null);

        $payloadBefore = $before !== null ? json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $payloadAfter = $after !== null ? json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $db->prepare('
            INSERT INTO admin_audit_logs (
                admin_user_id, action, entity_type, entity_id, payload_before, payload_after, ip_address, user_agent
            ) VALUES (
                :admin_user_id, :action, :entity_type, :entity_id, :payload_before, :payload_after, :ip_address, :user_agent
            )
        ');

        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload_before' => $payloadBefore,
            'payload_after' => $payloadAfter,
            'ip_address' => $ip,
            'user_agent' => $ua,
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Retrieves all audit log entries for a given entity ordered chronologically.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getLogsForEntity(PDO $pdo, string $entityType, string $entityId): array
    {
        $stmt = $pdo->prepare('
            SELECT * FROM admin_audit_logs 
            WHERE entity_type = :entity_type AND entity_id = :entity_id 
            ORDER BY id ASC
        ');
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
