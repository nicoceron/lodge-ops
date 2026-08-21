<?php

namespace App\Data\Payments;

final readonly class QrOrderRequest
{
    public function __construct(
        public string $externalReference,
        public string $idempotencyKey,
        public string $requestChecksum,
        public int $amountMinor,
        public string $currency,
        public string $description,
        public string $externalPosId,
        public string $mode,
        public string $expirationTime = 'PT15M',
    ) {}
}
