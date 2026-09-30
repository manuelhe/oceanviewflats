<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Search;

/**
 * Immutable query criteria DTO for administrative reservation searches.
 */
final class ReservationSearchCriteria
{
    public const ALLOWED_SORT_COLUMNS = [
        'created_at' => 'created_at',
        'check_in' => 'check_in',
        'total_price' => 'total_price',
        'status' => 'status',
        'guest_name' => 'guest_name',
    ];

    public function __construct(
        public readonly ?string $query = null,
        public readonly string $propertyId = 'all',
        public readonly string $status = 'all',
        public readonly ?string $checkInFrom = null,
        public readonly ?string $checkInTo = null,
        public readonly int $page = 1,
        public readonly int $limit = 10,
        public readonly string $sortBy = 'check_in',
        public readonly string $sortDir = 'desc',
        public readonly string $registryStatus = 'all',
        public readonly string $source = 'all'
    ) {}

    /**
     * Factory method hydrating criteria from request or filter parameters.
     *
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        // 1. Text query
        $rawQuery = $params['query'] ?? $params['search'] ?? null;
        $query = null;
        if (is_string($rawQuery)) {
            $trimmed = trim($rawQuery);
            $query = $trimmed !== '' ? $trimmed : null;
        }

        // 2. Property ID filter
        $rawPropertyId = $params['property_id'] ?? $params['propertyId'] ?? 'all';
        $propertyId = is_string($rawPropertyId) && trim($rawPropertyId) !== '' ? trim($rawPropertyId) : 'all';

        // 3. Status filter
        $rawStatus = $params['status'] ?? 'all';
        $status = is_string($rawStatus) && trim($rawStatus) !== '' ? trim($rawStatus) : 'all';

        // 4. Check-in range
        $rawCheckInFrom = $params['check_in_from'] ?? $params['checkInFrom'] ?? null;
        $checkInFrom = null;
        if (is_string($rawCheckInFrom)) {
            $trimmed = trim($rawCheckInFrom);
            $checkInFrom = $trimmed !== '' ? $trimmed : null;
        }

        $rawCheckInTo = $params['check_in_to'] ?? $params['checkInTo'] ?? null;
        $checkInTo = null;
        if (is_string($rawCheckInTo)) {
            $trimmed = trim($rawCheckInTo);
            $checkInTo = $trimmed !== '' ? $trimmed : null;
        }

        // 5. Pagination
        $page = max(1, (int) ($params['page'] ?? 1));
        $rawLimit = $params['limit'] ?? $params['per_page'] ?? $params['perPage'] ?? 10;
        $limit = max(1, min(100, (int) $rawLimit));

        // 6. Sorting
        $rawSortBy = (string) ($params['sort_by'] ?? $params['sortBy'] ?? 'check_in');
        $sortBy = self::ALLOWED_SORT_COLUMNS[strtolower(trim($rawSortBy))] ?? 'check_in';

        $rawSortDir = strtolower(trim((string) ($params['sort_dir'] ?? $params['sortDir'] ?? 'desc')));
        $sortDir = ($rawSortDir === 'asc') ? 'asc' : 'desc';

        // 7. Registry & Source filters
        $rawRegistry = (string) ($params['registry_status'] ?? $params['registryStatus'] ?? 'all');
        $registryStatus = trim($rawRegistry) !== '' ? trim($rawRegistry) : 'all';

        $rawSource = (string) ($params['source'] ?? 'all');
        $source = trim($rawSource) !== '' ? trim($rawSource) : 'all';

        return new self(
            query: $query,
            propertyId: $propertyId,
            status: $status,
            checkInFrom: $checkInFrom,
            checkInTo: $checkInTo,
            page: $page,
            limit: $limit,
            sortBy: $sortBy,
            sortDir: $sortDir,
            registryStatus: $registryStatus,
            source: $source
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'search' => $this->query,
            'property_id' => $this->propertyId,
            'status' => $this->status,
            'check_in_from' => $this->checkInFrom,
            'check_in_to' => $this->checkInTo,
            'page' => $this->page,
            'limit' => $this->limit,
            'per_page' => $this->limit,
            'sort_by' => $this->sortBy,
            'sort_dir' => $this->sortDir,
            'registry_status' => $this->registryStatus,
            'source' => $this->source,
        ];
    }
}
