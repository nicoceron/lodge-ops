<?php

namespace App\Data\Payments;

final readonly class ProviderOrderTransaction
{
    public function __construct(
        public string $id,
        public int $amountMinor,
        public string $status,
        public ?string $statusDetail = null,
        public ?int $paidAmountMinor = null,
        public ?int $refundedAmountMinor = null,
        public ?string $referenceId = null,
        public ?string $paymentMethodType = null,
        public ?string $paymentMethodId = null,
        public ?int $installments = null,
    ) {}
}
