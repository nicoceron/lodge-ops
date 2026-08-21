<?php

namespace App\Data\Payments;

use App\Enums\PaymentChannel;

final readonly class ProviderPaymentApplication
{
    public function __construct(
        public string $providerTransactionId,
        public string $providerAccount,
        public PaymentChannel $channel,
        public string $method,
        public string $externalReference,
        public int $amountMinor,
        public string $currency,
        public ?string $providerOrderId = null,
        public array $settlement = [],
    ) {}

    public static function checkoutPro(ProviderPayment $payment): self
    {
        return new self(
            $payment->id,
            $payment->providerAccount,
            PaymentChannel::OnlineCheckout,
            'mercado_pago_checkout_pro',
            $payment->externalReference,
            $payment->amountMinor,
            $payment->currency,
            settlement: $payment->settlement,
        );
    }
}
