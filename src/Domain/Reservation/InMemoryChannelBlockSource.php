<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * In-memory adapter for testing ephemeral external channel blocks.
 */
final class InMemoryChannelBlockSource implements ChannelBlockSourceInterface
{
    /** @var array<string, list<ChannelBlock>> */
    private array $blocks = [];

    /**
     * @param list<ChannelBlock> $initialBlocks
     */
    public function __construct(array $initialBlocks = [])
    {
        foreach ($initialBlocks as $block) {
            $this->addBlock($block);
        }
    }

    public function addBlock(ChannelBlock $block): void
    {
        $this->blocks[$block->propertyId][] = $block;
    }

    public function getBlocks(string $propertyId): array
    {
        return $this->blocks[$propertyId] ?? [];
    }

    public function getBlockedNights(string $propertyId): array
    {
        $nights = [];
        foreach ($this->getBlocks($propertyId) as $block) {
            foreach ($block->nights() as $night) {
                $nights[] = $night;
            }
        }
        return array_values(array_unique($nights));
    }
}
