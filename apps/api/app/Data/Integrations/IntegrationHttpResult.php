<?php

namespace App\Data\Integrations;

final readonly class IntegrationHttpResult
{
    /** @param array<string,mixed>|null $json */
    public function __construct(
        public int $status,
        public ?array $json,
        public string $requestChecksum,
        public string $responseChecksum,
        public int $latencyMs,
        public int $attempts,
    ) {}
}
