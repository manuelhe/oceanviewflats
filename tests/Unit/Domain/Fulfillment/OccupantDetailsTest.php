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
            'first_name' => 'Carlos',
            'last_name' => 'Ruiz',
            'middle_name' => null,
            'second_last_name' => null,
            'phone' => null,
            'country' => null,
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

    public function testStructuredNameInstantiationSynthesizesFullName(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            firstName: 'Maria',
            middleName: 'Fernanda',
            lastName: 'Gomez',
            secondLastName: 'Perez',
            age: 32,
            docType: 'Passport',
            docNum: 'PA12345678',
            phone: '+57 300 123 4567',
            country: 'COLOMBIA'
        );

        $this->assertSame('Maria Fernanda Gomez Perez', $occupant->name);
        $this->assertSame('Maria', $occupant->firstName);
        $this->assertSame('Fernanda', $occupant->middleName);
        $this->assertSame('Gomez', $occupant->lastName);
        $this->assertSame('Perez', $occupant->secondLastName);
        $this->assertSame('+57 300 123 4567', $occupant->phone);
        $this->assertSame('COLOMBIA', $occupant->country);
    }

    public function testLegacyFullNameSplitsIntoFirstAndLastName(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            name: 'John Michael Doe',
            age: 40,
            docType: 'Passport',
            docNum: 'US987654'
        );

        $this->assertSame('John Michael Doe', $occupant->name);
        $this->assertSame('John', $occupant->firstName);
        $this->assertSame('Michael Doe', $occupant->lastName);
    }

    public function testSingleWordLegacyNameSplitsGracefully(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            name: 'Cher',
            age: 50,
            docType: 'Passport',
            docNum: 'CH12345'
        );

        $this->assertSame('Cher', $occupant->name);
        $this->assertSame('Cher', $occupant->firstName);
        $this->assertSame('Cher', $occupant->lastName);
    }

    public function testPhoneValidation(): void
    {
        $occupant = new OccupantDetails(
            index: 1,
            name: 'John Doe',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            phone: '+1-555-0199'
        );

        $this->assertSame('+1-555-0199', $occupant->phone);
    }

    public function testInvalidShortPhoneThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant phone must be between 6 and 30 characters.');

        new OccupantDetails(
            index: 1,
            name: 'John Doe',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            phone: '1234'
        );
    }

    public function testInvalidLongPhoneThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Occupant phone must be between 6 and 30 characters.');

        new OccupantDetails(
            index: 1,
            name: 'John Doe',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            phone: str_repeat('1', 35)
        );
    }

    public function testCountryNormalization(): void
    {
        $occupant1 = new OccupantDetails(
            index: 1,
            name: 'John Doe',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            country: 'USA'
        );
        $this->assertSame('ESTADOS UNIDOS', $occupant1->country);

        $occupant2 = new OccupantDetails(
            index: 1,
            name: 'Juan Perez',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            country: 'Spain'
        );
        $this->assertSame('ESPANA', $occupant2->country);

        $occupant3 = new OccupantDetails(
            index: 1,
            name: 'Taro Yamada',
            age: 30,
            docType: 'Passport',
            docNum: 'P12345',
            country: 'Japón'
        );
        $this->assertSame('JAPON', $occupant3->country);
    }

    public function testPortalDocTypeMapping(): void
    {
        $passport = new OccupantDetails(index: 1, name: 'A B', age: 20, docType: 'Passport', docNum: '12');
        $this->assertSame('PA', $passport->getPortalDocType());

        $cedula = new OccupantDetails(index: 1, name: 'A B', age: 20, docType: 'Cédula de Ciudadanía', docNum: '12');
        $this->assertSame('CC', $cedula->getPortalDocType());

        $ti = new OccupantDetails(index: 1, name: 'A B', age: 15, docType: 'Tarjeta de Identidad', docNum: '12');
        $this->assertSame('TI', $ti->getPortalDocType());

        $rc = new OccupantDetails(index: 1, name: 'A B', age: 5, docType: 'Registro Civil', docNum: '12');
        $this->assertSame('RC', $rc->getPortalDocType());

        $license = new OccupantDetails(index: 1, name: 'A B', age: 25, docType: 'Driver License', docNum: '12');
        $this->assertSame('DE', $license->getPortalDocType());

        $foreign = new OccupantDetails(index: 1, name: 'A B', age: 25, docType: 'Cédula de Extranjería', docNum: '12');
        $this->assertSame('CE', $foreign->getPortalDocType());
    }

    public function testFromArraySupportsStructuredKeys(): void
    {
        $data = [
            'guest_first_name_1' => 'Andres',
            'guest_last_name_1' => 'Cepeda',
            'guest_phone_1' => '+573100000000',
            'guest_country_1' => 'COLOMBIA',
            'guest_age_1' => 45,
            'guest_doc_type_1' => 'Cédula de Ciudadanía',
            'guest_doc_num_1' => '79000111',
        ];

        $occupant = OccupantDetails::fromArray($data, 1);
        $this->assertSame('Andres Cepeda', $occupant->name);
        $this->assertSame('Andres', $occupant->firstName);
        $this->assertSame('Cepeda', $occupant->lastName);
        $this->assertSame('+573100000000', $occupant->phone);
        $this->assertSame('COLOMBIA', $occupant->country);
        $this->assertSame('CC', $occupant->getPortalDocType());
    }
}
