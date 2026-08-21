<?php

namespace Tests\Fakes;

use App\Contracts\Payments\PaymentGateway;
use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\HostedCheckout;
use App\Data\Payments\ProviderDispute;
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

    /** @var array<string, ProviderDispute> */
    public array $disputes = [];

    /** @var list<CheckoutRequest> */
    public array $checkouts = [];

    /** @var array<string, HostedCheckout> */
    public array $hostedByIdempotencyKey = [];

    public bool $failAfterCheckoutCreationOnce = false;

    /** @var list<ProviderRefundRequest> */
    public array $refunds = [];

    /** @var array<string, ProviderRefund> */
    public array $refundResults = [];

    /** @var list<array{payment_id: string, refund_id: string}> */
    public array $fetchRefundCalls = [];

    public function for(IntegrationConnection $connection): PaymentGateway
    {
        return $this;
    }

    public function createHostedCheckout(CheckoutRequest $request): HostedCheckout
    {
        if (isset($this->hostedByIdempotencyKey[$request->idempotencyKey])) {
            return $this->hostedByIdempotencyKey[$request->idempotencyKey];
        }
        $this->checkouts[] = $request;
        $hosted = new HostedCheckout('pref-'.$request->externalReference, 'https://sandbox.mercadopago.com/checkout/v1/redirect?pref_id=test');
        $this->hostedByIdempotencyKey[$request->idempotencyKey] = $hosted;
        if ($this->failAfterCheckoutCreationOnce) {
            $this->failAfterCheckoutCreationOnce = false;
            throw new \RuntimeException('Simulated timeout after remote checkout creation.');
        }

        return $hosted;
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        return $this->payments[$providerPaymentId] ?? throw new \RuntimeException('Fake provider payment not found.');
    }

    public function fetchDispute(string $providerDisputeId): ProviderDispute
    {
        return $this->disputes[$providerDisputeId] ?? throw new \RuntimeException('Fake provider dispute not found.');
    }

    public function refund(ProviderRefundRequest $request): ProviderRefund
    {
        $this->refunds[] = $request;

        $result = new ProviderRefund(
            'refund-'.$request->idempotencyKey,
            $request->providerPaymentId,
            'approved',
            $request->amountMinor,
            $request->currency,
            $this->payments[$request->providerPaymentId]->providerAccount ?? 'seller-1',
        );
        $this->refundResults[$result->id] = $result;

        return $result;
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund
    {
        $this->fetchRefundCalls[] = ['payment_id' => $providerPaymentId, 'refund_id' => $providerRefundId];

        return $this->refundResults[$providerRefundId]
            ?? throw new \RuntimeException('Fake provider refund not found.');
    }

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent
    {
        $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);

        return new VerifiedProviderEvent($request->headers['x-request-id'], 'payment', 'payment', 'payment.updated', (string) data_get($payload, 'data.id'), $payload);
    }
}
