<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Access;

use OceanViewFlats\Domain\Access\DoorCodeGenerator;
use PHPUnit\Framework\TestCase;

final class DoorCodeGeneratorTest extends TestCase
{
    public function testGeneratesCodeFromStandardDocumentNumber(): void
    {
        // 10-digit ID: 1020304050 -> last 6 digits are 304050 -> '0304050#'
        $code = DoorCodeGenerator::generate('1020304050');
        $this->assertSame('0304050#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testGeneratesCodeFromExactSixDigitDocument(): void
    {
        // Exact 6 digits: 123456 -> '0123456#'
        $code = DoorCodeGenerator::generate('123456');
        $this->assertSame('0123456#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testPadsShortDocumentNumberWithLeadingZeros(): void
    {
        // Short ID: 1234 -> padded to 6 digits '001234' -> '0001234#'
        $code = DoorCodeGenerator::generate('1234');
        $this->assertSame('0001234#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testStripsNonDigitCharactersFromDocument(): void
    {
        // ID with letters, spaces, dots, dashes: CC 1.020.304.050-K
        $code = DoorCodeGenerator::generate('CC 1.020.304.050-K');
        $this->assertSame('0304050#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testFallsBackToPhoneWhenDocumentContainsNoDigits(): void
    {
        // Document has only letters: 'PASSPORT'
        // Phone: '+57 300 987 6543' -> digits '573009876543' -> last 6 '876543' -> '0876543#'
        $code = DoorCodeGenerator::generate('PASSPORT', '+57 300 987 6543');
        $this->assertSame('0876543#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testFallsBackToPhoneAndPadsWhenPhoneIsShort(): void
    {
        // Document: 'ABC', Phone: '99' -> digits '99' -> padded to '000099' -> '0000099#'
        $code = DoorCodeGenerator::generate('ABC', '99');
        $this->assertSame('0000099#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testHandlesCompletelyEmptyDocumentAndPhoneGracefully(): void
    {
        // Empty document and empty phone -> '0000000#'
        $code = DoorCodeGenerator::generate('', '');
        $this->assertSame('0000000#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testHandlesNonDigitStringsForBothDocAndPhone(): void
    {
        $code = DoorCodeGenerator::generate('N/A', 'NONE');
        $this->assertSame('0000000#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testAlwaysReturnsSevenDigitsPlusHash(): void
    {
        $samples = [
            ['72345678', ''],
            ['A1B2C3D4E5F6', ''],
            ['555', ''],
            ['', '3001234567'],
            ['SPECIAL-DOC-1', '']
        ];

        foreach ($samples as [$doc, $phone]) {
            $code = DoorCodeGenerator::generate($doc, $phone);
            $this->assertSame(8, strlen($code), "Code '$code' must be exactly 8 characters");
            $this->assertStringStartsWith('0', $code, "Code '$code' must start with '0'");
            $this->assertStringEndsWith('#', $code, "Code '$code' must end with '#'");
            $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
        }
    }

    public function testGenerateRandomProducesValidCodeFormat(): void
    {
        $code = DoorCodeGenerator::generateRandom();
        $this->assertSame(8, strlen($code));
        $this->assertStringStartsWith('0', $code);
        $this->assertStringEndsWith('#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }

    public function testGenerateForPropertyProducesValidCodeFormat(): void
    {
        $code = DoorCodeGenerator::generateForProperty('1606');
        $this->assertSame(8, strlen($code));
        $this->assertStringStartsWith('0', $code);
        $this->assertStringEndsWith('#', $code);
        $this->assertMatchesRegularExpression('/^0\d{6}#$/', $code);
    }
}
