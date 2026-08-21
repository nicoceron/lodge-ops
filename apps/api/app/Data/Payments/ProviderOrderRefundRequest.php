<?php

namespace App\Data\Payments;

final readonly class ProviderOrderRefundRequest
{
    public function __construct(
        public string $providerOrderId,
        public string $providerTransactionId,
        public string $idempotencyKey,
        public string $requestChecksum,
        public string $currency,
        public ?int $amountMinor = null,
    ) {}
}
