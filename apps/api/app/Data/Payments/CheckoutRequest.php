<?php

namespace App\Data\Payments;

final readonly class CheckoutRequest
{
    public function __construct(
        public string $externalReference,
        public string $idempotencyKey,
        public int $amountMinor,
        public string $currency,
        public string $description,
        public string $successUrl,
        public string $pendingUrl,
        public string $failureUrl,
        public string $webhookUrl,
        public ?string $payerEmail = null,
    ) {}
}
