<?php

namespace App\Data\Payments;

final readonly class ProviderPayment
{
    /** @param array<string, mixed> $settlement */
    public function __construct(
        public string $id,
        public string $externalReference,
        public string $status,
        public ?string $statusDetail,
        public int $amountMinor,
        public string $currency,
        public string $providerAccount,
        public array $settlement = [],
    ) {}
}
