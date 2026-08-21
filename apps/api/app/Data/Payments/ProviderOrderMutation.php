<?php

namespace App\Data\Payments;

final readonly class ProviderOrderMutation
{
    public function __construct(
        public string $providerOrderId,
        public string $idempotencyKey,
        public string $requestChecksum,
    ) {}
}
