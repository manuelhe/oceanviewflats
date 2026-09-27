<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use OceanViewFlats\Domain\Reservation\Reservation;

final class InMemorySpreadsheetSync implements SpreadsheetSyncInterface
{
    /**
     * @var list<array{reservation: Reservation, extra: array<string, mixed>}>
     */
    private array $syncedRecords = [];

    public function __construct(
        private bool $shouldFail = false
    ) {}

    public function setShouldFail(bool $shouldFail): void
    {
        $this->shouldFail = $shouldFail;
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function sync(Reservation $reservation, array $extra = []): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->syncedRecords[] = [
            'reservation' => $reservation,
            'extra' => $extra,
        ];

        return true;
    }

    /**
     * @return list<array{reservation: Reservation, extra: array<string, mixed>}>
     */
    public function getSyncedRecords(): array
    {
        return $this->syncedRecords;
    }

    public function count(): int
    {
        return count($this->syncedRecords);
    }

    public function clear(): void
    {
        $this->syncedRecords = [];
    }
}
