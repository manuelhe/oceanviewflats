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
            primaryGuestEmail: 'alice@example.com',
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
        $this->assertSame('alice@example.com', $submission->primaryGuestEmail);
        $this->assertSame('alice@example.com', $submission->getPrimaryOccupant()?->email);
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
            occupants: [],
            primaryGuestEmail: 'primary@example.com'
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
            occupants: ['not-an-occupant'],
            primaryGuestEmail: 'primary@example.com'
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
            primaryGuestEmail: 'alice@example.com',
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
        $this->assertSame('alice@example.com', $array['primary_guest_email']);
        $this->assertSame('alice@example.com', $array['guest_email_1']);
        $this->assertSame('ABC-789', $array['car_plates']);
        $this->assertSame('Mazda CX-5', $array['car_model']);
        $this->assertSame('127.0.0.1', $array['ip_address']);
        $this->assertSame('es', $array['lang']);
        $this->assertCount(1, $array['occupants']);
        $this->assertSame('Alice Smith', $array['occupants'][0]['name']);
        $this->assertSame('alice@example.com', $array['occupants'][0]['email']);
    }

    public function testFromArrayWithStructuredOccupants(): void
    {
        $data = [
            'reservation_code' => 'res_struct_1',
            'property_id' => '1707',
            'check_in' => '2026-11-10',
            'check_out' => '2026-11-15',
            'primary_guest_email' => 'maria.lopez@example.com',
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
        $this->assertSame('maria.lopez@example.com', $submission->primaryGuestEmail);
        $primary = $submission->getPrimaryOccupant();
        $this->assertNotNull($primary);
        $this->assertSame('Maria Lopez', $primary->name);
        $this->assertSame('maria.lopez@example.com', $primary->email);
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
            'guest_email_1' => 'carlos.gomez@example.com',
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
        $this->assertSame('carlos.gomez@example.com', $submission->primaryGuestEmail);
        $this->assertNull($submission->carPlates);
        $this->assertNull($submission->carModel);
        $this->assertSame('192.168.1.50', $submission->ipAddress);
        $this->assertSame('es', $submission->lang);

        $primary = $submission->getPrimaryOccupant();
        $this->assertNotNull($primary);
        $this->assertSame('Carlos Gomez', $primary->name);
        $this->assertSame('79001122', $primary->docNum);
        $this->assertSame('carlos.gomez@example.com', $primary->email);

        $second = $submission->occupants[1];
        $this->assertSame('Ana Gomez', $second->name);
        $this->assertSame('52003344', $second->docNum);
        $this->assertNull($second->email);
    }

    public function testThrowsWhenPrimaryGuestEmailIsMissingOrInvalid(): void
    {
        $occupant = new OccupantDetails(1, 'Jane Doe', 30, 'Passport', 'P12345');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primary guest email must be a valid email address up to 100 characters.');

        new GuestRegistrySubmission(
            reservationCode: 'res_invalid_email',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occupant],
            primaryGuestEmail: 'not-an-email'
        );
    }

    public function testThrowsWhenPrimaryGuestEmailTooLong(): void
    {
        $occupant = new OccupantDetails(1, 'Jane Doe', 30, 'Passport', 'P12345');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primary guest email must be a valid email address up to 100 characters.');

        new GuestRegistrySubmission(
            reservationCode: 'res_long_email',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occupant],
            primaryGuestEmail: str_repeat('a', 95) . '@test.com'
        );
    }

    public function testThrowsWhenPrimaryOccupantEmailMismatchesPrimaryGuestEmail(): void
    {
        $occupant = new OccupantDetails(1, 'Jane Doe', 30, 'Passport', 'P12345', 'one@example.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Primary occupant email must match primary guest email.');

        new GuestRegistrySubmission(
            reservationCode: 'res_mismatch',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occupant],
            primaryGuestEmail: 'two@example.com'
        );
    }

    public function testFromArrayExtractsEmailFromVariousKeys(): void
    {
        // 1. primary_email
        $sub1 = GuestRegistrySubmission::fromArray([
            'reservation_code' => 'res_1',
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'primary_email' => 'primary@example.com',
            'occupants' => [['name' => 'Guest 1', 'age' => 20, 'doc_type' => 'Passport', 'doc_num' => 'P1']],
        ]);
        $this->assertSame('primary@example.com', $sub1->primaryGuestEmail);

        // 2. guest_email
        $sub2 = GuestRegistrySubmission::fromArray([
            'reservation_code' => 'res_2',
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'guest_email' => 'guest@example.com',
            'occupants' => [['name' => 'Guest 1', 'age' => 20, 'doc_type' => 'Passport', 'doc_num' => 'P1']],
        ]);
        $this->assertSame('guest@example.com', $sub2->primaryGuestEmail);

        // 3. fallback to occupants[0]['email']
        $sub3 = GuestRegistrySubmission::fromArray([
            'reservation_code' => 'res_3',
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'occupants' => [['name' => 'Guest 1', 'age' => 20, 'doc_type' => 'Passport', 'doc_num' => 'P1', 'email' => 'occupant@example.com']],
        ]);
        $this->assertSame('occupant@example.com', $sub3->primaryGuestEmail);
    }

    public function testCompanionGuestsEmailIsAlwaysNull(): void
    {
        // 1. In flat form data with companion emails
        $subForm = GuestRegistrySubmission::fromArray([
            'reservation_code' => 'res_companions_form',
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'primary_email' => 'primary@example.com',
            'guest_count' => 3,
            'guest_name_1' => 'Primary Guest',
            'guest_age_1' => 30,
            'guest_doc_type_1' => 'Passport',
            'guest_doc_num_1' => 'P111',
            'guest_name_2' => 'Companion Two',
            'guest_age_2' => 28,
            'guest_doc_type_2' => 'Passport',
            'guest_doc_num_2' => 'P222',
            'guest_email_2' => 'companion2@example.com',
            'guest_name_3' => 'Companion Three',
            'guest_age_3' => 26,
            'guest_doc_type_3' => 'Passport',
            'guest_doc_num_3' => 'P333',
            'guest_email_3' => 'companion3@example.com',
        ]);

        $this->assertSame('primary@example.com', $subForm->occupants[0]->email);
        $this->assertNull($subForm->occupants[1]->email);
        $this->assertNull($subForm->occupants[2]->email);

        // 2. In array form data where companion has email
        $subArray = GuestRegistrySubmission::fromArray([
            'reservation_code' => 'res_companions_arr',
            'property_id' => '1606',
            'check_in' => '2026-10-01',
            'check_out' => '2026-10-05',
            'primary_email' => 'primary@example.com',
            'occupants' => [
                ['name' => 'Primary', 'age' => 30, 'doc_type' => 'Passport', 'doc_num' => 'P1', 'email' => 'primary@example.com'],
                ['name' => 'Companion', 'age' => 25, 'doc_type' => 'Passport', 'doc_num' => 'P2', 'email' => 'companion@example.com'],
            ],
        ]);

        $this->assertSame('primary@example.com', $subArray->occupants[0]->email);
        $this->assertNull($subArray->occupants[1]->email);

        // 3. Directly passed OccupantDetails objects
        $occ1 = new OccupantDetails(1, 'Primary', 30, 'Passport', 'P1', 'primary@example.com');
        $occ2 = new OccupantDetails(2, 'Companion', 25, 'Passport', 'P2', 'companion@example.com');
        $subDirect = new GuestRegistrySubmission(
            reservationCode: 'res_companions_direct',
            propertyId: '1606',
            checkIn: '2026-10-01',
            checkOut: '2026-10-05',
            occupants: [$occ1, $occ2],
            primaryGuestEmail: 'primary@example.com'
        );

        $this->assertSame('primary@example.com', $subDirect->occupants[0]->email);
        $this->assertNull($subDirect->occupants[1]->email);
    }
}
