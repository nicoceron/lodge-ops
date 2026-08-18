<?php

namespace Tests\Fakes;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\HostedCheckout;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderRefund;
use App\Data\Payments\ProviderRefundRequest;
use App\Data\Payments\VerifiedProviderEvent;
use App\Data\Payments\WebhookRequest;
use App\Models\IntegrationConnection;

final class FakePaymentGateway implements PaymentGateway, PaymentGatewayFactory
{
    /** @var array<string, ProviderPayment> */
    public array $payments = [];

    /** @var list<CheckoutRequest> */
    public array $checkouts = [];

    /** @var list<ProviderRefundRequest> */
    public array $refunds = [];

    public function for(IntegrationConnection $connection): PaymentGateway
    {
        return $this;
    }

    public function createHostedCheckout(CheckoutRequest $request): HostedCheckout
    {
        $this->checkouts[] = $request;

        return new HostedCheckout('pref-'.$request->externalReference, 'https://sandbox.mercadopago.com/checkout/v1/redirect?pref_id=test');
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        return $this->payments[$providerPaymentId] ?? throw new \RuntimeException('Fake provider payment not found.');
    }

    public function refund(ProviderRefundRequest $request): ProviderRefund
    {
        $this->refunds[] = $request;

        return new ProviderRefund('refund-'.$request->idempotencyKey, $request->providerPaymentId, 'approved', $request->amountMinor, $request->currency);
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund
    {
        return new ProviderRefund($providerRefundId, $providerPaymentId, 'approved', 1, 'ARS');
    }

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent
    {
        $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);

        return new VerifiedProviderEvent($request->headers['x-request-id'], 'payment', 'payment', 'payment.updated', (string) data_get($payload, 'data.id'), $payload);
    }
}
