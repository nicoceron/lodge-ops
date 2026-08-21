<?php

namespace App\Data\Payments;

final readonly class ProviderOrderRefund
{
    public function __construct(
        public string $id,
        public string $providerOrderId,
        public string $providerTransactionId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $referenceId = null,
        public ?string $statusDetail = null,
    ) {}
}
