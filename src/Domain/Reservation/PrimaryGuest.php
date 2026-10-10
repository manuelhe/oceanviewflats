<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Value object representing the Primary Guest on a reservation.
 */
final class PrimaryGuest
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $lang = 'en'
    ) {
    }

    public static function create(
        string $name,
        string $email,
        string $phone = '',
        string $lang = 'en'
    ): self {
        return new self($name, $email, $phone, $lang);
    }
}
