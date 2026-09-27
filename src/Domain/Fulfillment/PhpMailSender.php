<?php

declare(strict_types=1);

namespace OceanViewFlats\Domain\Fulfillment;

final class PhpMailSender implements EmailSenderInterface
{
    public function __construct(
        private readonly string $fromAddress = 'OceanViewFlats <no-reply@oceanviewflats.com>',
        private readonly string $replyToAddress = 'rentals@oceanviewflats.com'
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function send(string $to, string $subject, string $htmlBody, array $headers = []): bool
    {
        $defaultHeaders = [
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'From' => $this->fromAddress,
            'Reply-To' => $this->replyToAddress,
            'X-Mailer' => 'PHP/' . phpversion(),
        ];

        $mergedHeaders = array_merge($defaultHeaders, $headers);

        $headerLines = [];
        foreach ($mergedHeaders as $name => $value) {
            $headerLines[] = sprintf('%s: %s', $name, $value);
        }
        $headerString = implode("\r\n", $headerLines);

        return mail($to, $subject, $htmlBody, $headerString);
    }
}
