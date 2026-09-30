<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Search;

/**
 * Immutable paginated outcome DTO for reservation search queries.
 */
final class ReservationSearchResult
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $totalCount,
        public readonly int $page,
        public readonly int $limit,
        public readonly int $totalPages
    ) {}

    /**
     * Factory method computing totalPages automatically.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function create(
        array $items,
        int $totalCount,
        int $page,
        int $limit
    ): self {
        $totalPages = $limit > 0 ? max(1, (int) ceil($totalCount / $limit)) : 1;
        return new self(
            items: $items,
            totalCount: $totalCount,
            page: $page,
            limit: $limit,
            totalPages: $totalPages
        );
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     totalCount: int,
     *     total_count: int,
     *     total: int,
     *     page: int,
     *     limit: int,
     *     per_page: int,
     *     totalPages: int,
     *     total_pages: int
     * }
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'totalCount' => $this->totalCount,
            'total_count' => $this->totalCount,
            'total' => $this->totalCount,
            'page' => $this->page,
            'limit' => $this->limit,
            'per_page' => $this->limit,
            'totalPages' => $this->totalPages,
            'total_pages' => $this->totalPages,
        ];
    }
}
