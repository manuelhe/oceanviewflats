<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testDefaultAttributesAreEmpty(): void
    {
        $request = new Request('GET', '/');

        $this->assertSame([], $request->getAttributes());
        $this->assertNull($request->getAttribute('non_existent'));
        $this->assertSame('default_val', $request->getAttribute('non_existent', 'default_val'));
    }

    public function testGetAttributeReturnsStoredValue(): void
    {
        $request = new Request('GET', '/', attributes: ['uid' => 'res-101', 'active' => true]);

        $this->assertSame('res-101', $request->getAttribute('uid'));
        $this->assertTrue($request->getAttribute('active'));
        $this->assertSame(['uid' => 'res-101', 'active' => true], $request->getAttributes());
    }

    public function testGetAttributeDistinguishesNullValueFromMissing(): void
    {
        $request = new Request('GET', '/', attributes: ['nullable_key' => null]);

        $this->assertNull($request->getAttribute('nullable_key', 'fallback'));
        $this->assertSame('fallback', $request->getAttribute('truly_missing', 'fallback'));
    }

    public function testWithAttributeReturnsNewInstanceAndPreservesImmutability(): void
    {
        $original = new Request('GET', '/reservations');
        $updated = $original->withAttribute('reservation_id', 42);

        $this->assertNotSame($original, $updated);
        $this->assertSame([], $original->getAttributes());
        $this->assertNull($original->getAttribute('reservation_id'));

        $this->assertSame(42, $updated->getAttribute('reservation_id'));
        $this->assertSame(['reservation_id' => 42], $updated->getAttributes());
    }

    public function testWithAttributeOverwritesExistingKey(): void
    {
        $original = new Request('GET', '/', attributes: ['key' => 'initial']);
        $updated = $original->withAttribute('key', 'modified');

        $this->assertSame('initial', $original->getAttribute('key'));
        $this->assertSame('modified', $updated->getAttribute('key'));
    }

    public function testWithAttributesMergesAttributesAndPreservesImmutability(): void
    {
        $original = new Request('GET', '/', attributes: ['existing' => 'val1', 'overwrite' => 'old']);
        $merged = $original->withAttributes(['overwrite' => 'new', 'additional' => 'val2']);

        $this->assertNotSame($original, $merged);
        $this->assertSame('old', $original->getAttribute('overwrite'));
        $this->assertNull($original->getAttribute('additional'));

        $this->assertSame('val1', $merged->getAttribute('existing'));
        $this->assertSame('new', $merged->getAttribute('overwrite'));
        $this->assertSame('val2', $merged->getAttribute('additional'));
    }

    public function testPreservesAllRequestPropertiesAcrossWithAttribute(): void
    {
        $original = new Request(
            method: 'POST',
            uri: '/login?ref=header',
            query: ['ref' => 'header'],
            post: ['email' => 'admin@test.com'],
            server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_HX_REQUEST' => 'true'],
            cookies: ['session_id' => 'abc123xyz'],
            attributes: ['initial' => '1']
        );

        $cloned = $original->withAttribute('added', '2');

        $this->assertSame('POST', $cloned->getMethod());
        $this->assertSame('/login?ref=header', $cloned->getUri());
        $this->assertSame('header', $cloned->getQuery('ref'));
        $this->assertSame(['ref' => 'header'], $cloned->getAllQuery());
        $this->assertSame('admin@test.com', $cloned->getPost('email'));
        $this->assertSame(['email' => 'admin@test.com'], $cloned->getAllPost());
        $this->assertSame('10.0.0.1', $cloned->getClientIp());
        $this->assertTrue($cloned->isHtmx());
        $this->assertSame('abc123xyz', $cloned->getCookie('session_id'));
        $this->assertTrue($cloned->isMutating());
        $this->assertSame(['initial' => '1', 'added' => '2'], $cloned->getAttributes());
    }

    public function testFromGlobalsInitializesEmptyAttributes(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test-path';

        $request = Request::fromGlobals();

        $this->assertSame([], $request->getAttributes());
        $this->assertSame('/test-path', $request->getUri());
    }
}
