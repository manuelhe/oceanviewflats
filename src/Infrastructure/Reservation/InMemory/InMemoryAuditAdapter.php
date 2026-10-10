<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation\InMemory;

use DateTimeImmutable;
use OceanViewFlats\Domain\Reservation\ActorContext;
use OceanViewFlats\Domain\Reservation\Port\AuditPort;

/**
 * In-memory test adapter for AuditPort recording audit trail entries.
 */
class InMemoryAuditAdapter implements AuditPort
{
    private int $idCounter = 0;

    /**
     * @var list<array{
     *     id: int,
     *     action: string,
     *     entityType: string,
     *     entityId: string,
     *     payloadBefore: array<string, mixed>|null,
     *     payloadAfter: array<string, mixed>|null,
     *     actor: ActorContext|null,
     *     timestamp: DateTimeImmutable
     * }>
     */
    private array $records = [];

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
    ): int {
        $this->idCounter++;
        $entry = [
            'id' => $this->idCounter,
            'action' => $action,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'payloadBefore' => $payloadBefore,
            'payloadAfter' => $payloadAfter,
            'actor' => $actor,
            'timestamp' => new DateTimeImmutable(),
        ];

        $this->records[] = $entry;
        return $this->idCounter;
    }

    /**
     * @return list<array{
     *     id: int,
     *     action: string,
     *     entityType: string,
     *     entityId: string,
     *     payloadBefore: array<string, mixed>|null,
     *     payloadAfter: array<string, mixed>|null,
     *     actor: ActorContext|null,
     *     timestamp: DateTimeImmutable
     * }>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{
     *     id: int,
     *     action: string,
     *     entityType: string,
     *     entityId: string,
     *     payloadBefore: array<string, mixed>|null,
     *     payloadAfter: array<string, mixed>|null,
     *     actor: ActorContext|null,
     *     timestamp: DateTimeImmutable
     * }>
     */
    public function getRecordsForEntity(string $entityType, string $entityId): array
    {
        return array_values(
            array_filter(
                $this->records,
                fn(array $r) => $r['entityType'] === $entityType && $r['entityId'] === $entityId
            )
        );
    }

    public function hasAction(string $action): bool
    {
        foreach ($this->records as $record) {
            if ($record['action'] === $action) {
                return true;
            }
        }
        return false;
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function clear(): void
    {
        $this->records = [];
        $this->idCounter = 0;
    }
}
