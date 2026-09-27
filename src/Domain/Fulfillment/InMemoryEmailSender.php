<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

final class InMemoryEmailSender implements EmailSenderInterface
{
    /**
     * @var list<array{to: string, subject: string, htmlBody: string, headers: array<string, string>}>
     */
    private array $sentMessages = [];

    public function __construct(
        private bool $shouldFail = false
    ) {}

    public function setShouldFail(bool $shouldFail): void
    {
        $this->shouldFail = $shouldFail;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $to, string $subject, string $htmlBody, array $headers = []): bool
    {
        if ($this->shouldFail) {
            return false;
        }

        $this->sentMessages[] = [
            'to' => $to,
            'subject' => $subject,
            'htmlBody' => $htmlBody,
            'headers' => $headers,
        ];

        return true;
    }

    /**
     * @return list<array{to: string, subject: string, htmlBody: string, headers: array<string, string>}>
     */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function count(): int
    {
        return count($this->sentMessages);
    }

    public function clear(): void
    {
        $this->sentMessages = [];
    }
}
