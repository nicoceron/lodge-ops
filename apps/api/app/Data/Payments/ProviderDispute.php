<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

final readonly class ProviderDispute
{
    public function __construct(
        public string $id,
        public string $providerPaymentId,
        public string $status,
        public ?string $statusDetail,
        public int $amountMinor,
        public string $currency,
        public string $providerAccount,
        public ?string $reason = null,
        public ?bool $coverageApplied = null,
        public ?bool $documentationRequired = null,
        public ?CarbonImmutable $documentationDeadline = null,
        public ?CarbonImmutable $providerCreatedAt = null,
        public ?CarbonImmutable $providerUpdatedAt = null,
    ) {}
}
