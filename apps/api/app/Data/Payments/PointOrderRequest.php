<?php

namespace App\Data\Payments;

final readonly class PointOrderRequest
{
    public function __construct(
        public string $externalReference,
        public string $idempotencyKey,
        public string $requestChecksum,
        public int $amountMinor,
        public string $currency,
        public string $description,
        public string $terminalId,
        public string $expirationTime = 'PT15M',
        public string $printOnTerminal = 'no_ticket',
        public ?string $ticketNumber = null,
        public ?string $defaultPaymentMethodType = null,
    ) {}
}
