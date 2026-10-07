<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use OceanViewFlats\Domain\Fulfillment\CurlHttpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CurlHttpTransportTest extends TestCase
{
    public function testFlattenPayloadFlattensMultiDimensionalArraysForMultipart(): void
    {
        $transport = new CurlHttpTransport();

        $reflection = new ReflectionMethod(CurlHttpTransport::class, 'flattenPayload');
        $reflection->setAccessible(true);

        $input = [
            'consecutivo' => '123',
            'typeid' => ['CC', 'TI'],
            'ide' => ['111', '222'],
            'nested' => [
                'sub' => ['a', 'b'],
            ],
        ];

        /** @var array<string, string> $flattened */
        $flattened = $reflection->invoke($transport, $input);

        $this->assertSame([
            'consecutivo' => '123',
            'typeid[0]' => 'CC',
            'typeid[1]' => 'TI',
            'ide[0]' => '111',
            'ide[1]' => '222',
            'nested[sub][0]' => 'a',
            'nested[sub][1]' => 'b',
        ], $flattened);
    }

    public function testNetworkFailureReturnsErrorArrayWithoutException(): void
    {
        $transport = new CurlHttpTransport(defaultTimeoutSeconds: 1);

        // Invalid non-routable port on localhost to guarantee quick failure
        $result = $transport->post('http://127.0.0.1:54321/invalid_test_endpoint', [
            'test' => 'val',
        ]);

        $this->assertSame(0, $result['statusCode']);
        $this->assertSame('', $result['body']);
        $this->assertNotNull($result['error']);
        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['cookies']);
    }
}
