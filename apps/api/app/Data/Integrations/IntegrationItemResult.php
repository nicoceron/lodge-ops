<?php

namespace App\Data\Integrations;

final readonly class IntegrationItemResult
{
    public function __construct(
        public ?string $localKey = null,
        public ?int $httpStatus = null,
        public ?int $latencyMs = null,
        public ?string $requestChecksum = null,
        public ?string $responseChecksum = null,
    ) {}
}
