<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Parameter DTO encapsulating a reservation cancellation request.
 */
final class CancellationRequest
{
    public function __construct(
        public readonly string $reason,
        public readonly RefundInstruction $refundInstruction,
        public readonly bool $sendCancellationEmail = true,
        public readonly ?ActorContext $actor = null
    ) {
    }

    public static function withoutRefund(
        string $reason,
        ?ActorContext $actor = null,
        bool $sendCancellationEmail = true
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: RefundInstruction::none(),
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor
        );
    }

    public static function withFullRefund(
        string $reason,
        ?ActorContext $actor = null,
        bool $sendCancellationEmail = true
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: RefundInstruction::full(),
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor
        );
    }

    public static function withPartialRefund(
        string $reason,
        float $amountCop,
        ?ActorContext $actor = null,
        bool $sendCancellationEmail = true
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: RefundInstruction::partial($amountCop),
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor
        );
    }

    public static function create(
        string $reason,
        RefundInstruction $refundInstruction,
        bool $sendCancellationEmail = true,
        ?ActorContext $actor = null
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: $refundInstruction,
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor
        );
    }
}
