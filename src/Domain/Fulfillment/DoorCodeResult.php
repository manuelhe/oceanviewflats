<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

/**
 * Immutable typed outcome DTO for door PIN operations.
 */
final class DoorCodeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $doorCode = null,
        public readonly ?string $error = null
    ) {}

    public static function success(string $doorCode): self
    {
        return new self(
            success: true,
            doorCode: $doorCode,
            error: null
        );
    }

    public static function failure(string $error): self
    {
        return new self(
            success: false,
            doorCode: null,
            error: $error
        );
    }

    /**
     * @return array{success: bool, door_code: ?string, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'door_code' => $this->doorCode,
            'error' => $this->error,
        ];
    }
}
