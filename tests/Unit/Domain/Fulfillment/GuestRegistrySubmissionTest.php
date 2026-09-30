<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use InvalidArgumentException;
use OceanViewFlats\Domain\Fulfillment\GuestRegistrySubmission;
use OceanViewFlats\Domain\Fulfillment\OccupantDetails;
use PHPUnit\Framework\TestCase;

final class GuestRegistrySubmissionTest extends TestCase
{
    public function testInstantiationAndGetters(): void
    {
        $occupant1 = new OccupantDetails(1, 'Alice Smith', 30, 'Passport', 'P10001');
        $occupant2 = new OccupantDetails(2, 'Bob Smith', 32, 'Passport', 'P10002');

        $submission = new GuestRegistrySubmission(
            reservationCode: 'ovf_res_123',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occupant1, $occupant2],
            carPlates: 'XYZ-123',
            carModel: 'Toyota RAV4',
            ipAddress: '190.25.10.5',
            lang: 'en'
        );

        $this->assertSame('ovf_res_123', $submission->reservationCode);
        $this->assertSame('1606', $submission->propertyId);
        $this->assertSame('2026-10-01', $submission->checkIn);
        $this->assertSame('2026-10-05', $submission->checkOut);
        $this->assertSame(2, $submission->getGuestCount());
        $this->assertSame($occupant1, $submission->getPrimaryOccupant());
        $this->assertSame('XYZ-123', $submission->carPlates);
        $this->assertSame('Toyota RAV4', $submission->carModel);
        $this->assertSame('190.25.10.5', $submission->ipAddress);
        $this->assertSame('en', $submission->lang);
    }

    public function testGetPrimaryOccupantReturnsNullWhenEmpty(): void
    {
        $submission = new GuestRegistrySubmission(
            reservationCode: 'ovf_res_empty',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: []
        );

        $this->assertSame(0, $submission->getGuestCount());
        $this->assertNull($submission->getPrimaryOccupant());
    }

    public function testThrowsWhenOccupantNotInstanceOfOccupantDetails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All occupants must be instances of OccupantDetails.');

        new GuestRegistrySubmission(
            reservationCode: 'ovf_res_err',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: ['not-an-occupant']
        );
    }

    public function testToArraySerializesSubmission(): void
    {
        $occupant = new OccupantDetails(1, 'Alice Smith', 30, 'Passport', 'P10001');
        $submission = new GuestRegistrySubmission(
            reservationCode: 'res_abc',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occupant],
            carPlates: 'ABC-789',
            carModel: 'Mazda CX-5',
            ipAddress: '127.0.0.1',
            lang: 'es'
        );

        $array = $submission->toArray();

        $this->assertSame('res_abc', $array['reservation_code']);
        $this->assertSame('1606', $array['property_id']);
        $this->assertSame('2026-10-01', $array['check_in']);
        $this->assertSame('2026-10-05', $array['check_out']);
        $this->assertSame('ABC-789', $array['car_plates']);
        $this->assertSame('Mazda CX-5', $array['car_model']);
        $this->assertSame('127.0.0.1', $array['ip_address']);
        $this->assertSame('es', $array['lang']);
        $this->assertCount(1, $array['occupants']);
        $this->assertSame('Alice Smith', $array['occupants'][0]['name']);
    }

    public function testFromArrayWithStructuredOccupants(): void
    {
        $data = [
            'reservation_code' => 'res_struct_1',
            'property_id' => '1707',
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-15',
            'occupants' => [
                [
                    'index' => 1,
                    'name' => 'Maria Lopez',
                    'age' => 29,
                    'doc_type' => 'National ID',
                    'doc_num' => '987654321',
                ],
            ],
            'car_plates' => 'COL-999',
            'car_model' => 'Renault Duster',
            'ip_address' => '181.50.20.1',
            'lang' => 'es',
        ];

        $submission = GuestRegistrySubmission::fromArray($data);

        $this->assertSame('res_struct_1', $submission->reservationCode);
        $this->assertSame('1707', $submission->propertyId);
        $this->assertSame(1, $submission->getGuestCount());
        $primary = $submission->getPrimaryOccupant();
        $this->assertNotNull($primary);
        $this->assertSame('Maria Lopez', $primary->name);
        $this->assertSame('COL-999', $submission->carPlates);
        $this->assertSame('Renault Duster', $submission->carModel);
    }

    public function testFromArrayWithFlatFormData(): void
    {
        $postData = [
            'code' => 'res_flat_99',
            'property' => '1707',
            'check_in' => '2026-12-01',
            'check_out' => '2026-12-06',
            'guest_count' => '2',
            'guest_name_1' => 'Carlos Gomez',
            'guest_age_1' => '35',
            'guest_doc_type_1' => 'Cédula de Ciudadanía',
            'guest_doc_num_1' => '79001122',
            'guest_name_2' => 'Ana Gomez',
            'guest_age_2' => '33',
            'guest_doc_type_2' => 'Cédula de Ciudadanía',
            'guest_doc_num_2' => '52003344',
            'car_plates' => '',
            'car_model' => '',
            'REMOTE_ADDR' => '192.168.1.50',
            'lang' => '',
        ];

        $submission = GuestRegistrySubmission::fromArray($postData);

        $this->assertSame('res_flat_99', $submission->reservationCode);
        $this->assertSame('1707', $submission->propertyId);
        $this->assertSame('2026-12-01', $submission->checkIn);
        $this->assertSame('2026-12-06', $submission->checkOut);
        $this->assertSame(2, $submission->getGuestCount());
        $this->assertNull($submission->carPlates);
        $this->assertNull($submission->carModel);
        $this->assertSame('192.168.1.50', $submission->ipAddress);
        $this->assertSame('es', $submission->lang);

        $primary = $submission->getPrimaryOccupant();
        $this->assertNotNull($primary);
        $this->assertSame('Carlos Gomez', $primary->name);
        $this->assertSame('79001122', $primary->docNum);

        $second = $submission->occupants[1];
        $this->assertSame('Ana Gomez', $second->name);
        $this->assertSame('52003344', $second->docNum);
    }
}
