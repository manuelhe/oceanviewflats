<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use InvalidArgumentException;
use OceanViewFlats\Domain\Fulfillment\OccupantDetails;
use PHPUnit\Framework\TestCase;

final class OccupantDetailsTest extends TestCase
{
    public function testValidInstantiationAndGetters(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 32,
            docType: 'Passport',
            docNum: 'A12345678'
        );

        $this->assertSame(1, $occupant->index);
        $this->assertSame('Jane Doe', $occupant->name);
        $this->assertSame(32, $occupant->age);
        $this->assertSame('Passport', $occupant->docType);
        $this->assertSame('A12345678', $occupant->docNum);
        $this->assertNull($occupant->email);
    }

    public function testThrowsOnInvalidIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant index must be at least 1.');

        new OccupantDetails(
            index: 0,
            name: 'Jane Doe',
            age: 30,
            docType: 'Passport',
            docNum: '12345'
        );
    }

    public function testThrowsOnNameTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant name must be between 2 and 100 characters.');

        new OccupantDetails(
            index: 1,
            name: 'J',
            age: 30,
            docType: 'Passport',
            docNum: '12345'
        );
    }

    public function testThrowsOnNameTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant name must be between 2 and 100 characters.');

        new OccupantDetails(
            index: 1,
            name: str_repeat('A', 101),
            age: 30,
            docType: 'Passport',
            docNum: '12345'
        );
    }

    public function testThrowsOnAgeNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant age must be between 0 and 120.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: -1,
            docType: 'Passport',
            docNum: '12345'
        );
    }

    public function testThrowsOnAgeExceedingLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant age must be between 0 and 120.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 121,
            docType: 'Passport',
            docNum: '12345'
        );
    }

    public function testThrowsOnInvalidDocType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid document type: NonExistentType.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 30,
            docType: 'NonExistentType',
            docNum: '12345'
        );
    }

    public function testThrowsOnDocNumTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant document number must be between 2 and 50 characters.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 30,
            docType: 'Passport',
            docNum: 'X'
        );
    }

    public function testThrowsOnDocNumTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant document number must be between 2 and 50 characters.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 30,
            docType: 'Passport',
            docNum: str_repeat('9', 51)
        );
    }

    public function testToArrayProducesArray(): void
    {
        $occupant = new OccupantDetails(
            index: 2,
            name: 'Carlos Ruiz',
            age: 28,
            docType: 'Cédula de Ciudadanía',
            docNum: '1098765432'
        );

        $expected = [
            'index' => 2,
            'name' => 'Carlos Ruiz',
            'age' => 28,
            'doc_type' => 'Cédula de Ciudadanía',
            'doc_num' => '1098765432',
            'email' => null,
        ];

        $this->assertSame($expected, $occupant->toArray());
    }

    public function testValidInstantiationWithEmail(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 32,
            docType: 'Passport',
            docNum: 'A12345678',
            email: 'jane.doe@example.com'
        );

        $this->assertSame('jane.doe@example.com', $occupant->email);
        $this->assertSame('jane.doe@example.com', $occupant->toArray()['email']);
    }

    public function testThrowsOnInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant email must be a valid email address up to 100 characters.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 30,
            docType: 'Passport',
            docNum: '12345',
            email: 'not-a-valid-email'
        );
    }

    public function testThrowsOnEmailTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant email must be a valid email address up to 100 characters.');

        new OccupantDetails(
            index: 1,
            name: 'Jane Doe',
            age: 30,
            docType: 'Passport',
            docNum: '12345',
            email: str_repeat('a', 95) . '@test.com'
        );
    }

    public function testFromArrayWithEmail(): void
    {
        $data = [
            'index' => 1,
            'name' => 'Valid User',
            'age' => 25,
            'doc_type' => 'Passport',
            'doc_num' => 'ID-12345',
            'email' => 'valid.user@example.com',
        ];

        $occupant = OccupantDetails::fromArray($data);
        $this->assertSame('valid.user@example.com', $occupant->email);
    }

    public function testFromArrayThrowsOnInvalidDocType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid document type: Alien Registration Card.');

        $data = [
            'name' => 'Unknown Traveler',
            'age' => '45',
            'doc_type' => 'Alien Registration Card',
            'doc_num' => 'ARC-998811',
        ];

        OccupantDetails::fromArray($data, index: 3);
    }

    public function testFromArrayAcceptsValidDocTypes(): void
    {
        foreach (OccupantDetails::VALID_DOC_TYPES as $validType) {
            $data = [
                'index' => 1,
                'name' => 'Valid User',
                'age' => 25,
                'doc_type' => $validType,
                'doc_num' => 'ID-12345',
            ];

            $occupant = OccupantDetails::fromArray($data);
            $this->assertSame($validType, $occupant->docType);
        }
    }
}
