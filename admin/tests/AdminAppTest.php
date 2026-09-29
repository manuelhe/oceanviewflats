<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests;

use OceanViewFlats\Admin\AdminApp;
use PHPUnit\Framework\TestCase;

final class AdminAppTest extends TestCase
{
    public function testVersionReturnsSemanticVersion(): void
    {
        $this->assertSame('1.0.0', AdminApp::getVersion());
    }
}
