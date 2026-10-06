<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

use InvalidArgumentException;

/**
 * Immutable typed DTO carrying guest registration details.
 */
final class GuestRegistrySubmission
{
    /**
     * @var array<int, OccupantDetails>
     */
    public readonly array $occupants;

    /**
     * @param array<int, mixed> $occupants
     */
    public function __construct(
        public readonly string $reservationCode,
        public readonly string $propertyId,
        public readonly string $checkIn,
        public readonly string $checkOut,
        array $occupants,
        public readonly string $primaryGuestEmail,
        public readonly ?string $carPlates = null,
        public readonly ?string $carModel = null,
        public readonly ?string $ipAddress = null,
        public readonly string $lang = 'es'
    ) {
        $trimmedEmail = trim($this->primaryGuestEmail);
        if ($trimmedEmail === '' || !filter_var($trimmedEmail, FILTER_VALIDATE_EMAIL) || strlen($this->primaryGuestEmail) > 100) {
            throw new InvalidArgumentException('Primary guest email must be a valid email address up to 100 characters.');
        }

        $validated = [];
        $isFirst = true;
        foreach ($occupants as $occupant) {
            if (!$occupant instanceof OccupantDetails) {
                throw new InvalidArgumentException('All occupants must be instances of OccupantDetails.');
            }
            if ($isFirst) {
                $occEmail = isset($occupant->email) ? $occupant->email : null;
                if ($occEmail !== null && strcasecmp(trim($occEmail), $trimmedEmail) !== 0) {
                    throw new InvalidArgumentException('Primary occupant email must match primary guest email.');
                }
                if ($occEmail === null) {
                    try {
                        $occupant = new OccupantDetails(
                            index: $occupant->index,
                            name: $occupant->name,
                            age: $occupant->age,
                            docType: $occupant->docType,
                            docNum: $occupant->docNum,
                            email: $this->primaryGuestEmail
                        );
                    } catch (InvalidArgumentException) {
                        $ref = new \ReflectionClass($occupant);
                        if ($ref->hasProperty('email')) {
                            $ref->getProperty('email')->setValue($occupant, $this->primaryGuestEmail);
                        }
                    }
                }
                $isFirst = false;
            }
            $validated[] = $occupant;
        }
        $this->occupants = $validated;
    }

    public function getPrimaryOccupant(): ?OccupantDetails
    {
        return $this->occupants[0] ?? null;
    }

    public function getGuestCount(): int
    {
        return count($this->occupants);
    }

    public function hasValidDates(): bool
    {
        if ($this->checkIn === '' || $this->checkOut === '') {
            return false;
        }

        $d1 = \DateTimeImmutable::createFromFormat('Y-m-d', $this->checkIn);
        $d2 = \DateTimeImmutable::createFromFormat('Y-m-d', $this->checkOut);

        if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $this->checkIn || $d2->format('Y-m-d') !== $this->checkOut) {
            return false;
        }

        return $this->checkIn < $this->checkOut;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reservation_code' => $this->reservationCode,
            'property_id' => $this->propertyId,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'primary_guest_email' => $this->primaryGuestEmail,
            'guest_email_1' => $this->primaryGuestEmail,
            'occupants' => array_map(fn (OccupantDetails $o): array => $o->toArray(), $this->occupants),
            'car_plates' => $this->carPlates,
            'car_model' => $this->carModel,
            'ip_address' => $this->ipAddress,
            'lang' => $this->lang,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $reservationCode = trim((string)($data['reservation_code'] ?? $data['reservationCode'] ?? $data['code'] ?? ''));
        $propertyId = trim((string)($data['property_id'] ?? $data['propertyId'] ?? $data['property'] ?? ''));
        $checkIn = trim((string)($data['check_in'] ?? $data['checkIn'] ?? ''));
        $checkOut = trim((string)($data['check_out'] ?? $data['checkOut'] ?? ''));

        $primaryEmail = trim((string)(
            $data['guest_email_1']
            ?? $data['primary_guest_email']
            ?? $data['primaryGuestEmail']
            ?? $data['primary_email']
            ?? $data['guest_email']
            ?? ''
        ));

        $occupants = [];
        if (isset($data['occupants']) && is_array($data['occupants'])) {
            foreach ($data['occupants'] as $idx => $item) {
                if ($item instanceof OccupantDetails) {
                    $occupants[] = $item;
                } elseif (is_array($item)) {
                    $occupants[] = OccupantDetails::fromArray($item, is_int($idx) ? $idx + 1 : count($occupants) + 1);
                }
            }
        } elseif (isset($data['guests']) && is_array($data['guests'])) {
            foreach ($data['guests'] as $idx => $item) {
                if ($item instanceof OccupantDetails) {
                    $occupants[] = $item;
                } elseif (is_array($item)) {
                    $occupants[] = OccupantDetails::fromArray($item, is_int($idx) ? $idx + 1 : count($occupants) + 1);
                }
            }
        } else {
            $guestCountRaw = $data['guest_count'] ?? 1;
            $guestCount = min(6, max(1, (int)$guestCountRaw));
            for ($i = 1; $i <= $guestCount; $i++) {
                if (isset($data["guest_name_{$i}"])) {
                    $occupants[] = OccupantDetails::fromArray([
                        'index' => $i,
                        'name' => (string)($data["guest_name_{$i}"] ?? ''),
                        'age' => (int)($data["guest_age_{$i}"] ?? 0),
                        'doc_type' => (string)($data["guest_doc_type_{$i}"] ?? 'Other ID'),
                        'doc_num' => (string)($data["guest_doc_num_{$i}"] ?? ''),
                        'email' => $i === 1 ? ($primaryEmail !== '' ? $primaryEmail : null) : ($data["guest_email_{$i}"] ?? null),
                    ], $i);
                }
            }
        }

        if ($primaryEmail === '' && !empty($occupants) && $occupants[0]->email !== null) {
            $primaryEmail = $occupants[0]->email;
        }

        $carPlates = isset($data['car_plates']) || isset($data['carPlates'])
            ? (string)($data['car_plates'] ?? $data['carPlates'])
            : null;
        if ($carPlates !== null) {
            $carPlates = trim($carPlates);
            if ($carPlates === '') {
                $carPlates = null;
            }
        }

        $carModel = isset($data['car_model']) || isset($data['carModel'])
            ? (string)($data['car_model'] ?? $data['carModel'])
            : null;
        if ($carModel !== null) {
            $carModel = trim($carModel);
            if ($carModel === '') {
                $carModel = null;
            }
        }

        $ipAddress = isset($data['ip_address']) || isset($data['ipAddress']) || isset($data['REMOTE_ADDR'])
            ? (string)($data['ip_address'] ?? $data['ipAddress'] ?? $data['REMOTE_ADDR'])
            : null;
        if ($ipAddress !== null) {
            $ipAddress = trim($ipAddress);
            if ($ipAddress === '') {
                $ipAddress = null;
            }
        }

        $lang = trim((string)($data['lang'] ?? 'es'));
        if ($lang === '') {
            $lang = 'es';
        }

        return new self(
            reservationCode: $reservationCode,
            propertyId: $propertyId,
            checkIn: $checkIn,
            checkOut: $checkOut,
            occupants: $occupants,
            primaryGuestEmail: $primaryEmail,
            carPlates: $carPlates,
            carModel: $carModel,
            ipAddress: $ipAddress,
            lang: $lang
        );
    }
}
