<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Payment;

use JsonSerializable;

/**
 * Immutable typed value object representing the authoritative settlement outcome
 * of an asynchronous external payment gateway webhook.
 */
final class WebhookSettlementResult implements JsonSerializable
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ALREADY_CONFIRMED = 'already_confirmed';
    public const STATUS_IGNORED_CANCELLED_TERMINAL = 'ignored_cancelled_terminal';
    public const STATUS_REFUND_CANCELLED = 'refund_cancelled';
    public const STATUS_PARTIAL_REFUND_SYNCED = 'partial_refund_synced';
    public const STATUS_UNKNOWN_RESERVATION = 'unknown_reservation';
    public const STATUS_GATEWAY_ERROR = 'gateway_error';
    public const STATUS_DATABASE_ERROR = 'database_error';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly int $httpStatusCode,
        public readonly ?string $reservationUid = null,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function confirmed(
        string $reservationUid,
        array $data = [],
        ?string $message = 'Payment confirmed and reservation fulfilled.'
    ): self {
        return new self(
            success: true,
            status: self::STATUS_CONFIRMED,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function alreadyConfirmed(
        string $reservationUid,
        array $data = [],
        ?string $message = 'Reservation already confirmed.'
    ): self {
        return new self(
            success: true,
            status: self::STATUS_ALREADY_CONFIRMED,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ignoredCancelled(
        string $reservationUid,
        array $data = [],
        ?string $message = 'Reservation is cancelled (terminal state). Refusing resurrection.'
    ): self {
        return new self(
            success: true,
            status: self::STATUS_IGNORED_CANCELLED_TERMINAL,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function refundCancelled(
        string $reservationUid,
        array $data = [],
        ?string $message = 'Full refund processed and reservation cancelled.'
    ): self {
        return new self(
            success: true,
            status: self::STATUS_REFUND_CANCELLED,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function partialRefundSynced(
        string $reservationUid,
        array $data = [],
        ?string $message = 'Partial refund synchronized.'
    ): self {
        return new self(
            success: true,
            status: self::STATUS_PARTIAL_REFUND_SYNCED,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function unknownReservation(
        ?string $reservationUid = null,
        array $data = [],
        ?string $message = 'Reservation not found for external reference.',
        int $httpStatusCode = 200
    ): self {
        return new self(
            success: false,
            status: self::STATUS_UNKNOWN_RESERVATION,
            httpStatusCode: $httpStatusCode,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function gatewayError(
        string $message,
        int $httpStatusCode = 502,
        array $data = [],
        ?string $reservationUid = null
    ): self {
        return new self(
            success: false,
            status: self::STATUS_GATEWAY_ERROR,
            httpStatusCode: $httpStatusCode,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function databaseError(
        string $message,
        int $httpStatusCode = 500,
        array $data = [],
        ?string $reservationUid = null
    ): self {
        return new self(
            success: false,
            status: self::STATUS_DATABASE_ERROR,
            httpStatusCode: $httpStatusCode,
            reservationUid: $reservationUid,
            message: $message,
            data: $data
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function unhandledStatus(
        string $status,
        ?string $reservationUid = null,
        array $data = [],
        ?string $message = null
    ): self {
        return new self(
            success: true,
            status: $status,
            httpStatusCode: 200,
            reservationUid: $reservationUid,
            message: $message ?? sprintf('Unhandled payment status: %s', $status),
            data: $data
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'http_status_code' => $this->httpStatusCode,
            'reservation_uid' => $this->reservationUid,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
