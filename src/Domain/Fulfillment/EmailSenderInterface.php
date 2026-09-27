<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

interface EmailSenderInterface
{
    /**
     * Sends an HTML email message.
     *
     * @param string $to Recipient email address
     * @param string $subject Subject line
     * @param string $htmlBody HTML content
     * @param array<string, string> $headers Additional mail headers
     * @return bool True if mail was accepted for delivery, false otherwise
     */
    public function send(string $to, string $subject, string $htmlBody, array $headers = []): bool;
}
