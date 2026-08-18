<?php

namespace App\Data\Payments;

final readonly class ProviderRefundRequest
{
    public function __construct(
        public string $providerPaymentId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
    ) {}
}
