<?php

namespace App\Data\Payments;

final readonly class WebhookRequest
{
    /** @param array<string, string> $headers @param array<string, mixed> $query */
    public function __construct(
        public string $rawBody,
        public array $headers,
        public array $query,
        public int $toleranceSeconds = 300,
    ) {}
}
