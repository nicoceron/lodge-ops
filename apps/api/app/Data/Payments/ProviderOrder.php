<?php

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

final readonly class ProviderOrder
{
    /**
     * @param  list<ProviderOrderTransaction>  $payments
     * @param  list<ProviderOrderRefund>  $refunds
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $providerAccount,
        public string $externalReference,
        public string $status,
        public ?string $statusDetail,
        public int $amountMinor,
        public string $currency,
        public array $payments,
        public array $refunds = [],
        public ?string $terminalId = null,
        public ?string $externalPosId = null,
        public ?string $qrMode = null,
        public ?string $qrData = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $updatedAt = null,
    ) {}
}
