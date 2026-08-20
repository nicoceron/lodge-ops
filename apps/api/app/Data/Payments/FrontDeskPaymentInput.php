<?php

namespace App\Data\Payments;

use App\Enums\PaymentChannel;

final readonly class FrontDeskPaymentInput
{
    public function __construct(
        public string $reservationId,
        public PaymentChannel $channel,
        public int $amountMinor,
        public string $idempotencyKey,
        public ?string $depositId = null,
        public ?string $processorAlias = null,
        public ?string $merchantAccountAlias = null,
        public ?string $terminalIdentifier = null,
        public ?string $transactionReference = null,
        public ?string $authorizationReference = null,
        public ?string $batchReference = null,
        public ?string $cardBrand = null,
        public ?string $cardLastFour = null,
        public ?string $note = null,
        /** @var list<string> */
        public array $luhnFalsePositiveFields = [],
        public ?string $luhnFalsePositiveJustification = null,
    ) {}

    /** @return array<string, mixed> */
    public function checksumPayload(): array
    {
        return get_object_vars($this);
    }
}
