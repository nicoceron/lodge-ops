<?php

namespace App\Data\Payments;

final readonly class ProviderRefund
{
    public function __construct(
        public string $id,
        public string $providerPaymentId,
        public string $status,
        public int $amountMinor,
        public string $currency,
    ) {}
}
