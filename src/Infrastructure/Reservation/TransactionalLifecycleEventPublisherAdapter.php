<?php

declare(strict_types=1);

namespace OceanViewFlats\Infrastructure\Reservation;

use Throwable;
use OceanViewFlats\Domain\Fulfillment\CancellationEmailRendererInterface;
use OceanViewFlats\Domain\Fulfillment\EmailSenderInterface;
use OceanViewFlats\Domain\Fulfillment\GuestLifecycleFulfillmentServiceInterface;
use OceanViewFlats\Domain\Reservation\Event\ReservationCancelledEvent;
use OceanViewFlats\Domain\Reservation\Event\ReservationConfirmedEvent;
use OceanViewFlats\Domain\Reservation\Port\LifecycleEventPublisherPort;

/**
 * Production transactional lifecycle event publisher adapter.
 * Dispatches confirmation emails via fulfillment service and cancellation notices via email sender.
 */
final class TransactionalLifecycleEventPublisherAdapter implements LifecycleEventPublisherPort
{
    /**
     * @var list<callable(object): void>
     */
    private array $listeners = [];

    public function __construct(
        private readonly ?GuestLifecycleFulfillmentServiceInterface $fulfillmentService = null,
        private readonly ?CancellationEmailRendererInterface $cancellationRenderer = null,
        private readonly ?EmailSenderInterface $emailSender = null
    ) {
    }

    /**
     * Registers a post-commit event listener callback.
     *
     * @param callable(object): void $listener
     */
    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @inheritDoc
     */
    public function publishConfirmed(ReservationConfirmedEvent $event): void
    {
        try {
            if ($event->sendConfirmationEmail && $this->fulfillmentService !== null) {
                $this->fulfillmentService->fulfillBookingConfirmation($event->reservation);
            }
        } catch (Throwable) {
            // Best effort post-commit notification resilience
        }

        $this->notifyListeners($event);
    }

    /**
     * @inheritDoc
     */
    public function publishCancelled(ReservationCancelledEvent $event): void
    {
        try {
            if ($event->cancellationResult->emailSent && $this->cancellationRenderer !== null && $this->emailSender !== null) {
                $reservation = $event->reservation;
                $refundAmount = $event->cancellationResult->refundAmountCop;
                $policyRetention = $event->cancellationResult->policyRetentionCop;

                $subject = $this->cancellationRenderer->renderGuestSubject($reservation);
                $htmlBody = $this->cancellationRenderer->renderGuestCancellationHtml(
                    $reservation,
                    $refundAmount,
                    $policyRetention
                );

                $this->emailSender->send($reservation->guestEmail, $subject, $htmlBody);
            }
        } catch (Throwable) {
            // Best effort post-commit notification resilience
        }

        $this->notifyListeners($event);
    }

    private function notifyListeners(object $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (Throwable) {
                // Prevent individual listener failures from bubbling
            }
        }
    }
}
