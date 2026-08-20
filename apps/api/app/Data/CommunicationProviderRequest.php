<?php

namespace App\Data;

final readonly class CommunicationProviderRequest
{
    /** @param list<array{filename:string,content:string,content_type:string}> $attachments */
    public function __construct(
        public string $idempotencyKey,
        public string $apiKey,
        public string $from,
        public ?string $replyTo,
        public string $recipient,
        public string $subject,
        public string $text,
        public string $html,
        public array $attachments = [],
    ) {}
}
