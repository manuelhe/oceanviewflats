<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Repository;

use PDO;

/**
 * Administrative read-model repository for querying reservations,
 * guest registries, and operational audit logs.
 */
final class AdminReservationRepository
{
    private const ALLOWED_SORT_COLUMNS = [
        'created_at' => 'created_at',
        'check_in' => 'check_in',
        'total_price' => 'total_price',
        'status' => 'status',
        'guest_name' => 'guest_name',
    ];

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * Searches and paginates reservations based on multi-field filters.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     items: list<array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     per_page: int,
     *     total_pages: int
     * }
     */
    public function searchReservations(array $filters = []): array
    {
        $where = [];
        $params = [];

        // 1. Property ID filter
        $propertyId = isset($filters['property_id']) ? trim((string) $filters['property_id']) : '';
        if ($propertyId !== '' && $propertyId !== 'all') {
            $where[] = 'property_id = :property_id';
            $params['property_id'] = $propertyId;
        }

        // 2. Status filter
        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($status !== '' && $status !== 'all') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }

        // 3. Registry status filter
        $registryStatus = isset($filters['registry_status']) ? trim((string) $filters['registry_status']) : '';
        if ($registryStatus === 'completed') {
            $where[] = 'registry_completed = 1';
        } elseif ($registryStatus === 'pending') {
            $where[] = 'registry_completed = 0';
        }

        // 4. Source filter
        $source = isset($filters['source']) ? trim((string) $filters['source']) : '';
        if ($source !== '' && $source !== 'all') {
            $where[] = 'source = :source';
            $params['source'] = $source;
        }

        // 5. Text search on name, email, phone, or reservation_uid
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $where[] = '(guest_name LIKE :search OR guest_email LIKE :search OR guest_phone LIKE :search OR reservation_uid LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        // 6. Check-in date range
        $checkInFrom = isset($filters['check_in_from']) ? trim((string) $filters['check_in_from']) : '';
        if ($checkInFrom !== '') {
            $where[] = 'check_in >= :check_in_from';
            $params['check_in_from'] = $checkInFrom;
        }

        $checkInTo = isset($filters['check_in_to']) ? trim((string) $filters['check_in_to']) : '';
        if ($checkInTo !== '') {
            $where[] = 'check_in <= :check_in_to';
            $params['check_in_to'] = $checkInTo;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        // 7. Total count query
        $countSql = "SELECT COUNT(*) FROM reservations {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // 8. Pagination calculation
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limitRaw = $filters['limit'] ?? $filters['per_page'] ?? 25;
        $perPage = max(1, min(100, (int) $limitRaw));
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages && $total > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // 9. Sorting
        $sortByInput = isset($filters['sort_by']) ? (string) $filters['sort_by'] : 'created_at';
        $sortCol = self::ALLOWED_SORT_COLUMNS[$sortByInput] ?? 'created_at';

        $sortDirInput = isset($filters['sort_dir']) ? strtoupper((string) $filters['sort_dir']) : 'DESC';
        $sortDir = $sortDirInput === 'ASC' ? 'ASC' : 'DESC';

        // 10. Data fetch query
        $dataSql = "
            SELECT * FROM reservations 
            {$whereClause} 
            ORDER BY {$sortCol} {$sortDir}, id DESC 
            LIMIT :limit OFFSET :offset
        ";

        $dataStmt = $this->pdo->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        /** @var list<array<string, mixed>> $items */
        $items = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieves a reservation by UID along with its associated audit trail.
     *
     * @return array{
     *     reservation: array<string, mixed>,
     *     audit_logs: list<array<string, mixed>>
     * }|null
     */
    public function findReservationWithAuditTrail(string $uid): ?array
    {
        $reservation = $this->findReservationByUid($uid);

        if ($reservation === null) {
            return null;
        }

        $logStmt = $this->pdo->prepare('
            SELECT l.*, u.name AS admin_user_name, u.email AS admin_user_email
            FROM admin_audit_logs l
            LEFT JOIN admin_users u ON l.admin_user_id = u.id
            WHERE l.entity_type = :entity_type AND l.entity_id = :entity_id
            ORDER BY l.created_at DESC, l.id DESC
        ');
        $logStmt->execute([
            'entity_type' => 'reservation',
            'entity_id' => $uid,
        ]);
        /** @var list<array<string, mixed>> $auditLogs */
        $auditLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'reservation' => $reservation,
            'audit_logs' => $auditLogs,
        ];
    }

    /**
     * Retrieves guest registry record for a given reservation UID, decoding structured guest entries.
     *
     * @return array<string, mixed>|null
     */
    public function findGuestRegistryByReservationUid(string $uid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM guest_registries WHERE reservation_uid = :uid ORDER BY id DESC LIMIT 1');
        $stmt->execute(['uid' => $uid]);
        /** @var array<string, mixed>|false $registry */
        $registry = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($registry === false) {
            return null;
        }

        if (isset($registry['guests_payload']) && is_string($registry['guests_payload'])) {
            $decoded = json_decode($registry['guests_payload'], true);
            $registry['guests_payload'] = is_array($decoded) ? $decoded : [];
        }

        return $registry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findReservationByUid(string $uid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE reservation_uid = :uid LIMIT 1');
        $stmt->execute(['uid' => $uid]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function updateRegistryCompleted(string $uid, ?string $doorCode = null): bool
    {
        if ($doorCode !== null) {
            $stmt = $this->pdo->prepare('
                UPDATE reservations
                SET registry_completed = 1,
                    registry_completed_at = CURRENT_TIMESTAMP,
                    door_code = :door_code,
                    updated_at = CURRENT_TIMESTAMP
                WHERE reservation_uid = :uid
            ');
            return $stmt->execute([
                'door_code' => $doorCode,
                'uid' => $uid,
            ]) && $stmt->rowCount() > 0;
        }

        $stmt = $this->pdo->prepare('
            UPDATE reservations
            SET registry_completed = 1,
                registry_completed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE reservation_uid = :uid
        ');
        return $stmt->execute(['uid' => $uid]) && $stmt->rowCount() > 0;
    }

    public function updateDoorCode(string $uid, string $doorCode): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE reservations
            SET door_code = :door_code,
                updated_at = CURRENT_TIMESTAMP
            WHERE reservation_uid = :uid
        ');
        return $stmt->execute([
            'door_code' => $doorCode,
            'uid' => $uid,
        ]) && $stmt->rowCount() > 0;
    }

    /**
     * @param array{
     *     reservation_uid: string,
     *     property_id: string,
     *     guest_name: string,
     *     guest_email: string,
     *     guest_phone: string,
     *     check_in: string,
     *     check_out: string,
     *     total_price: float|int,
     *     source?: string,
     *     notes?: ?string,
     *     door_code?: ?string,
     *     registry_completed?: int,
     *     registry_completed_at?: ?string,
     *     status?: string,
     *     payment_status?: string,
     *     lang?: string
     * } $data
     */
    public function createManualReservation(array $data): string
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO reservations (
                reservation_uid,
                property_id,
                guest_name,
                guest_email,
                guest_phone,
                check_in,
                check_out,
                total_price,
                source,
                notes,
                door_code,
                registry_completed,
                registry_completed_at,
                status,
                payment_status,
                lang,
                created_at,
                updated_at
            ) VALUES (
                :reservation_uid,
                :property_id,
                :guest_name,
                :guest_email,
                :guest_phone,
                :check_in,
                :check_out,
                :total_price,
                :source,
                :notes,
                :door_code,
                :registry_completed,
                :registry_completed_at,
                :status,
                :payment_status,
                :lang,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ');

        $stmt->execute([
            'reservation_uid' => $data['reservation_uid'],
            'property_id' => $data['property_id'],
            'guest_name' => $data['guest_name'],
            'guest_email' => $data['guest_email'],
            'guest_phone' => $data['guest_phone'],
            'check_in' => $data['check_in'],
            'check_out' => $data['check_out'],
            'total_price' => $data['total_price'],
            'source' => $data['source'] ?? 'manual_override',
            'notes' => $data['notes'] ?? null,
            'door_code' => $data['door_code'] ?? null,
            'registry_completed' => $data['registry_completed'] ?? 0,
            'registry_completed_at' => $data['registry_completed_at'] ?? null,
            'status' => $data['status'] ?? 'confirmed',
            'payment_status' => $data['payment_status'] ?? 'approved',
            'lang' => $data['lang'] ?? 'es',
        ]);

        return $data['reservation_uid'];
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }
}
