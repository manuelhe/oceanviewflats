<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Reservation;

/**
 * Parameter DTO encapsulating a reservation cancellation request.
 */
final class CancellationRequest
{
    public readonly RefundInstruction $refundInstruction;
    public readonly ?float $refundAmountCop;

    public function __construct(
        public readonly string $reason,
        ?RefundInstruction $refundInstruction = null,
        public readonly bool $sendCancellationEmail = true,
        public readonly ?ActorContext $actor = null,
        public readonly bool $dispatchGatewayRefund = true,
        public readonly ?string $externalRefundId = null,
        ?float $refundAmountCop = null
    ) {
        $this->refundAmountCop = $refundAmountCop ?? $refundInstruction?->amountCop;
        if ($refundInstruction !== null) {
            $this->refundInstruction = $refundInstruction;
        } elseif ($refundAmountCop !== null && $refundAmountCop > 0.0) {
            $this->refundInstruction = RefundInstruction::partial($refundAmountCop);
        } else {
            $this->refundInstruction = RefundInstruction::full();
        }
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

    public static function fromWebhookRefund(
        string $reason,
        RefundInstruction $refundInstruction,
        ?string $externalRefundId = null,
        ?ActorContext $actor = null,
        bool $sendCancellationEmail = true
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: $refundInstruction,
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor ?? ActorContext::webhook(),
            dispatchGatewayRefund: false,
            externalRefundId: $externalRefundId
        );
    }

    public static function create(
        string $reason,
        RefundInstruction $refundInstruction,
        bool $sendCancellationEmail = true,
        ?ActorContext $actor = null,
        bool $dispatchGatewayRefund = true,
        ?string $externalRefundId = null
    ): self {
        return new self(
            reason: $reason,
            refundInstruction: $refundInstruction,
            sendCancellationEmail: $sendCancellationEmail,
            actor: $actor,
            dispatchGatewayRefund: $dispatchGatewayRefund,
            externalRefundId: $externalRefundId
        );
    }
}
