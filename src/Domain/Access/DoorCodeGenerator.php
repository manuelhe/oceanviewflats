<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Access;

/**
 * Pure domain service generating standardized 7-digit smart lock keypad codes
 * followed by '#' for guests per physical lock requirements.
 *
 * Algorithm:
 * 1. Extract digits from Primary Guest Government ID / Passport number.
 * 2. If no digits found, fallback to Primary Guest phone number digits.
 * 3. Extract last 6 digits, left-padded with '0' if fewer than 6 digits.
 * 4. Prefix with '0' and append '#' (Format: 0XXXXXX#).
 */
final class DoorCodeGenerator
{
    public const CODE_PREFIX = '0';
    public const SUFFIX = '#';
    public const CORE_DIGIT_LENGTH = 6;

    public static function generate(string $documentNumber, string $phone = ''): string
    {
        $digits = preg_replace('/\D/', '', $documentNumber);

        if ($digits === '') {
            $digits = preg_replace('/\D/', '', $phone);
        }

        $core = substr($digits, -self::CORE_DIGIT_LENGTH);
        $paddedCore = str_pad($core, self::CORE_DIGIT_LENGTH, '0', STR_PAD_LEFT);

        return self::CODE_PREFIX . $paddedCore . self::SUFFIX;
    }

    /**
     * Generates a fresh, non-deterministic 7-digit keypad code followed by '#'
     * for smart lock PIN regeneration or administrative overrides.
     */
    public static function generateRandom(): string
    {
        $randomCore = (string) random_int(100000, 999999);
        return self::CODE_PREFIX . $randomCore . self::SUFFIX;
    }

    /**
     * Alias for generateRandom() satisfying property-specific regeneration interface requirements.
     */
    public static function generateForProperty(string $propertyId = ''): string
    {
        return self::generateRandom();
    }
}
