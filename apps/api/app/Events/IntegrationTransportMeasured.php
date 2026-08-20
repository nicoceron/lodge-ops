<?php

namespace App\Events;

final readonly class IntegrationTransportMeasured
{
    public function __construct(
        public string $tenantId,
        public string $connectionId,
        public string $outcome,
        public ?int $httpStatus,
        public int $latencyMs,
        public int $attempt,
        public string $requestChecksum,
        public ?string $responseChecksum = null,
    ) {}
}
