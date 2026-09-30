<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use InvalidArgumentException;

/**
 * Immutable typed value object representing a registered occupant.
 */
final class OccupantDetails
{
    public const VALID_DOC_TYPES = [
        'Passport',
        'National ID',
        'Driver License',
        'Other ID',
        'Cédula de Ciudadanía',
        'Tarjeta de Identidad',
        'Registro Civil',
    ];

    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly int $age,
        public readonly string $docType,
        public readonly string $docNum
    ) {
        if ($this->index < 1) {
            throw new InvalidArgumentException('Occupant index must be at least 1.');
        }

        $trimmedName = trim($this->name);
        if (strlen($trimmedName) < 2 || strlen($this->name) > 100) {
            throw new InvalidArgumentException('Occupant name must be between 2 and 100 characters.');
        }

        if ($this->age < 0 || $this->age > 120) {
            throw new InvalidArgumentException('Occupant age must be between 0 and 120.');
        }

        if (!in_array($this->docType, self::VALID_DOC_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid document type: %s.', $this->docType));
        }

        $trimmedDoc = trim($this->docNum);
        if (strlen($trimmedDoc) < 2 || strlen($this->docNum) > 50) {
            throw new InvalidArgumentException('Occupant document number must be between 2 and 50 characters.');
        }
    }

    /**
     * @return array{index: int, name: string, age: int, doc_type: string, doc_num: string}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'age' => $this->age,
            'doc_type' => $this->docType,
            'doc_num' => $this->docNum,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, int $index = 1): self
    {
        $idx = (int)($data['index'] ?? $index);
        $name = trim((string)($data['name'] ?? ''));
        $age = (int)($data['age'] ?? 0);

        $docType = trim((string)($data['doc_type'] ?? $data['docType'] ?? 'Other ID'));
        if (!in_array($docType, self::VALID_DOC_TYPES, true)) {
            $docType = 'Other ID';
        }

        $docNum = trim((string)($data['doc_num'] ?? $data['docNum'] ?? ''));

        return new self(
            index: $idx,
            name: $name,
            age: $age,
            docType: $docType,
            docNum: $docNum
        );
    }
}
