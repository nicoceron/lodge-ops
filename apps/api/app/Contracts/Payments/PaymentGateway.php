<?php

namespace App\Contracts\Payments;

use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\HostedCheckout;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderRefund;
use App\Data\Payments\ProviderRefundRequest;
use App\Data\Payments\VerifiedProviderEvent;
use App\Data\Payments\WebhookRequest;

interface PaymentGateway
{
    public function createHostedCheckout(CheckoutRequest $request): HostedCheckout;

    public function fetchPayment(string $providerPaymentId): ProviderPayment;

    public function refund(ProviderRefundRequest $request): ProviderRefund;

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund;

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent;
}
