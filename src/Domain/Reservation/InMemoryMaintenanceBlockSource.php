<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * In-memory adapter for testing authoritative administrative maintenance blocks per ADR 0006.
 */
final class InMemoryMaintenanceBlockSource implements MaintenanceBlockSourceInterface
{
    /** @var array<string, list<MaintenanceBlock>> */
    private array $blocks = [];

    /**
     * @param list<MaintenanceBlock> $initialBlocks
     */
    public function __construct(array $initialBlocks = [])
    {
        foreach ($initialBlocks as $block) {
            $this->addBlock($block);
        }
    }

    public function addBlock(MaintenanceBlock $block): void
    {
        $this->blocks[$block->propertyId][] = $block;
    }

    /**
     * @return list<MaintenanceBlock>
     */
    public function getBlocks(string $propertyId): array
    {
        return $this->blocks[$propertyId] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getBlockedNights(string $propertyId): array
    {
        $nights = [];
        foreach ($this->getBlocks($propertyId) as $block) {
            foreach ($block->nights() as $night) {
                $nights[] = $night;
            }
        }
        $unique = array_values(array_unique($nights));
        sort($unique);
        return $unique;
    }
}
