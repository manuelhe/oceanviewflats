<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use OceanViewFlats\Domain\Fulfillment\DoorCodeResult;
use PHPUnit\Framework\TestCase;

final class DoorCodeResultTest extends TestCase
{
    public function testSuccessNamedConstructor(): void
    {
        $result = DoorCodeResult::success('884219#');

        $this->assertTrue($result->success);
        $this->assertSame('884219#', $result->doorCode);
        $this->assertNull($result->error);

        $array = $result->toArray();
        $this->assertTrue($array['success']);
        $this->assertSame('884219#', $array['door_code']);
        $this->assertNull($array['error']);
    }

    public function testFailureNamedConstructor(): void
    {
        $result = DoorCodeResult::failure('PIN must end in # and contain 4 to 10 digits.');

        $this->assertFalse($result->success);
        $this->assertNull($result->doorCode);
        $this->assertSame('PIN must end in # and contain 4 to 10 digits.', $result->error);

        $array = $result->toArray();
        $this->assertFalse($array['success']);
        $this->assertNull($array['door_code']);
        $this->assertSame('PIN must end in # and contain 4 to 10 digits.', $array['error']);
    }
}
