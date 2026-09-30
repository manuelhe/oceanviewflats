<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation\Search;

/**
 * Domain query port for administrative reservation search, pagination,
 * guest registry retrieval, and audit dossier construction.
 */
interface ReservationSearchInterface
{
    /**
     * Executes a paginated multi-field search for reservations.
     */
    public function search(ReservationSearchCriteria $criteria): ReservationSearchResult;

    /**
     * Aggregates a complete slide-over dossier for a reservation,
     * including registry data, audit logs, and refund history.
     */
    public function findWithAuditTrail(string $uid): ?ReservationDossier;

    /**
     * Retrieves guest registry payload and metadata by reservation UID.
     *
     * @return array<string, mixed>|null
     */
    public function findGuestRegistry(string $uid): ?array;

    /**
     * Retrieves refund ledger entries associated with a reservation UID.
     *
     * @return list<array<string, mixed>>
     */
    public function findRefunds(string $uid): array;
}
