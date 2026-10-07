<?php

declare(strict_types=1);

namespace OceanViewFlats\Tests\Unit\Domain\Fulfillment;

use InvalidArgumentException;
use OceanViewFlats\Domain\Fulfillment\CondominiumClearance;
use PHPUnit\Framework\TestCase;

final class CondominiumClearanceTest extends TestCase
{
    public function testCreatePendingInitializesCorrectState(): void
    {
        $payload = ['unit' => '1707', 'guest_count' => 2];
        $clearance = CondominiumClearance::createPending(
            reservationUid: 'ovf_res_test_123',
            propertyId: '1707',
            requestPayload: $payload
        );

        $this->assertSame('ovf_res_test_123', $clearance->reservationUid);
        $this->assertSame('1707', $clearance->propertyId);
        $this->assertSame(CondominiumClearance::STATUS_PENDING, $clearance->status);
        $this->assertTrue($clearance->isPending());
        $this->assertFalse($clearance->isSynced());
        $this->assertFalse($clearance->isFailed());
        $this->assertNull($clearance->clearanceNumber);
        $this->assertNull($clearance->errorMessage);
        $this->assertSame($payload, $clearance->requestPayload);
        $this->assertSame(1, $clearance->attempts);
        $this->assertNotNull($clearance->lastAttemptAt);
        $this->assertNull($clearance->syncedAt);
    }

    public function testMarkSyncedTransitionsState(): void
    {
        $clearance = CondominiumClearance::createPending('ovf_res_123', '1707');
        $synced = $clearance->markSynced('495', '2026-10-07 10:00:00');

        $this->assertSame(CondominiumClearance::STATUS_SYNCED, $synced->status);
        $this->assertTrue($synced->isSynced());
        $this->assertFalse($synced->isPending());
        $this->assertFalse($synced->isFailed());
        $this->assertSame('495', $synced->clearanceNumber);
        $this->assertNull($synced->errorMessage);
        $this->assertSame('2026-10-07 10:00:00', $synced->syncedAt);
    }

    public function testMarkSyncedThrowsOnEmptyClearanceNumber(): void
    {
        $clearance = CondominiumClearance::createPending('ovf_res_123', '1707');
        $this->expectException(InvalidArgumentException::class);
        $clearance->markSynced('   ');
    }

    public function testMarkFailedTransitionsState(): void
    {
        $clearance = CondominiumClearance::createPending('ovf_res_123', '1707');
        $failed = $clearance->markFailed('Huésped Manager returned HTTP 500');

        $this->assertSame(CondominiumClearance::STATUS_FAILED, $failed->status);
        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isPending());
        $this->assertFalse($failed->isSynced());
        $this->assertSame('Huésped Manager returned HTTP 500', $failed->errorMessage);
    }

    public function testRecordAttemptIncrementsCount(): void
    {
        $clearance = CondominiumClearance::createPending('ovf_res_123', '1707');
        $this->assertSame(1, $clearance->attempts);

        $attempt2 = $clearance->recordAttempt(['new' => 'payload']);
        $this->assertSame(2, $attempt2->attempts);
        $this->assertSame(['new' => 'payload'], $attempt2->requestPayload);

        $attempt3 = $attempt2->recordAttempt();
        $this->assertSame(3, $attempt3->attempts);
        $this->assertSame(['new' => 'payload'], $attempt3->requestPayload);
    }

    public function testThrowsOnEmptyReservationUidOrPropertyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CondominiumClearance::createPending('', '1707');
    }

    public function testThrowsOnInvalidStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CondominiumClearance(
            reservationUid: 'ovf_res_123',
            propertyId: '1707',
            status: 'unknown_status'
        );
    }

    public function testSerializationRoundTrip(): void
    {
        $original = new CondominiumClearance(
            reservationUid: 'ovf_res_roundtrip',
            propertyId: '1606',
            status: CondominiumClearance::STATUS_SYNCED,
            clearanceNumber: '502',
            errorMessage: null,
            requestPayload: ['foo' => 'bar'],
            attempts: 2,
            lastAttemptAt: '2026-10-07 10:00:00',
            syncedAt: '2026-10-07 10:01:00',
            createdAt: '2026-10-07 09:59:00',
            updatedAt: '2026-10-07 10:01:00',
            id: 42
        );

        $array = $original->toArray();
        $reconstructed = CondominiumClearance::fromArray($array);

        $this->assertSame(42, $reconstructed->id);
        $this->assertSame('ovf_res_roundtrip', $reconstructed->reservationUid);
        $this->assertSame('1606', $reconstructed->propertyId);
        $this->assertSame(CondominiumClearance::STATUS_SYNCED, $reconstructed->status);
        $this->assertSame('502', $reconstructed->clearanceNumber);
        $this->assertNull($reconstructed->errorMessage);
        $this->assertSame(['foo' => 'bar'], $reconstructed->requestPayload);
        $this->assertSame(2, $reconstructed->attempts);
        $this->assertSame('2026-10-07 10:00:00', $reconstructed->lastAttemptAt);
        $this->assertSame('2026-10-07 10:01:00', $reconstructed->syncedAt);
    }

    public function testFromArrayDecodesJsonStringPayload(): void
    {
        $data = [
            'id' => 1,
            'reservation_uid' => 'ovf_res_json',
            'property_id' => '1707',
            'status' => 'pending',
            'request_payload' => json_encode(['step1' => ['apt' => '1707']]),
            'attempts' => 1,
            'last_attempt_at' => '2026-10-07 12:00:00',
        ];

        $clearance = CondominiumClearance::fromArray($data);
        $this->assertSame(['step1' => ['apt' => '1707']], $clearance->requestPayload);
    }
}
