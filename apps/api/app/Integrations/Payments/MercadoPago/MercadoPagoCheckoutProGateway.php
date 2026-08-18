<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\HostedCheckout;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderRefund;
use App\Data\Payments\ProviderRefundRequest;
use App\Data\Payments\VerifiedProviderEvent;
use App\Data\Payments\WebhookRequest;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use JsonException;
use RuntimeException;

final class MercadoPagoCheckoutProGateway implements PaymentGateway
{
    public function __construct(
        private readonly MercadoPagoTransport $transport,
        private readonly string $webhookSecret,
        private readonly string $providerAccount,
        private readonly string $environment = 'sandbox',
    ) {}

    public function createHostedCheckout(CheckoutRequest $request): HostedCheckout
    {
        $payload = $this->transport->request('POST', '/checkout/preferences', [
            'external_reference' => $request->externalReference,
            'items' => [[
                'id' => $request->externalReference,
                'title' => $request->description,
                'quantity' => 1,
                'currency_id' => $request->currency,
                'unit_price' => $this->major($request->amountMinor),
            ]],
            'back_urls' => ['success' => $request->successUrl, 'pending' => $request->pendingUrl, 'failure' => $request->failureUrl],
            'auto_return' => 'approved',
            'notification_url' => $request->webhookUrl,
            'payer' => $request->payerEmail === null ? (object) [] : ['email' => $request->payerEmail],
        ], ['X-Idempotency-Key' => $request->idempotencyKey]);

        $id = data_get($payload, 'id');
        $url = data_get($payload, $this->environment === 'production' ? 'init_point' : 'sandbox_init_point')
            ?? data_get($payload, 'init_point');
        if (! is_string($id) || ! is_string($url) || ! $this->isAllowedCheckoutUrl($url)) {
            throw new RuntimeException('Mercado Pago returned an invalid hosted checkout.');
        }

        return new HostedCheckout($id, $url, isset($payload['expiration_date_to']) ? CarbonImmutable::parse($payload['expiration_date_to']) : null);
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        $payload = $this->transport->request('GET', '/v1/payments/'.rawurlencode($providerPaymentId));

        return new ProviderPayment(
            (string) data_get($payload, 'id'),
            (string) data_get($payload, 'external_reference'),
            (string) data_get($payload, 'status'),
            data_get($payload, 'status_detail'),
            $this->minor(data_get($payload, 'transaction_amount')),
            (string) data_get($payload, 'currency_id'),
            $this->providerAccount,
            [
                'gross_minor' => $this->minor(data_get($payload, 'transaction_amount')),
                'fee_minor' => $this->minor(collect(data_get($payload, 'fee_details', []))->sum('amount')),
                'net_minor' => $this->minor(data_get($payload, 'transaction_details.net_received_amount', 0)),
            ],
        );
    }

    public function refund(ProviderRefundRequest $request): ProviderRefund
    {
        $payload = $this->transport->request('POST', '/v1/payments/'.rawurlencode($request->providerPaymentId).'/refunds', [
            'amount' => $this->major($request->amountMinor),
        ], ['X-Idempotency-Key' => $request->idempotencyKey]);

        return $this->normalizeRefund($payload, $request->currency);
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund
    {
        $payload = $this->transport->request('GET', '/v1/payments/'.rawurlencode($providerPaymentId).'/refunds/'.rawurlencode($providerRefundId));

        return $this->normalizeRefund($payload, (string) data_get($payload, 'currency_id', 'ARS'));
    }

    public function verifyWebhook(WebhookRequest $request): VerifiedProviderEvent
    {
        $signature = $request->headers['x-signature'] ?? '';
        $requestId = $request->headers['x-request-id'] ?? '';
        preg_match('/(?:^|,)ts=([^,]+)/', $signature, $timestampMatch);
        preg_match('/(?:^|,)v1=([^,]+)/', $signature, $signatureMatch);
        $timestamp = $timestampMatch[1] ?? null;
        $provided = $signatureMatch[1] ?? null;
        $dataId = $request->query['data.id'] ?? $request->query['data_id'] ?? data_get($request->query, 'data.id') ?? $request->query['id'] ?? null;
        if (! ctype_digit((string) $timestamp) || ! is_string($provided) || $requestId === '' || ! is_string($dataId) || $dataId === '') {
            throw new RuntimeException('Invalid Mercado Pago webhook signature headers.');
        }
        if (abs(now()->timestamp - (int) $timestamp) > $request->toleranceSeconds) {
            throw new RuntimeException('Stale Mercado Pago webhook signature.');
        }
        $dataId = strtolower($dataId);
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$timestamp};";
        if (! hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), strtolower($provided))) {
            throw new RuntimeException('Invalid Mercado Pago webhook signature.');
        }

        try {
            $payload = json_decode($request->rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed Mercado Pago webhook JSON.', previous: $exception);
        }
        if (! is_array($payload)) {
            throw new RuntimeException('Malformed Mercado Pago webhook payload.');
        }

        return new VerifiedProviderEvent(
            $requestId,
            data_get($payload, 'type'),
            data_get($payload, 'type'),
            data_get($payload, 'action'),
            $dataId,
            $payload,
            isset($payload['date_created']) ? CarbonImmutable::parse($payload['date_created']) : null,
        );
    }

    private function normalizeRefund(array $payload, string $currency): ProviderRefund
    {
        $id = data_get($payload, 'id');
        $paymentId = data_get($payload, 'payment_id');
        if ((! is_string($id) && ! is_int($id)) || (! is_string($paymentId) && ! is_int($paymentId))) {
            throw new RuntimeException('Mercado Pago returned a malformed refund.');
        }

        return new ProviderRefund((string) $id, (string) $paymentId, (string) data_get($payload, 'status'), $this->minor(data_get($payload, 'amount')), $currency);
    }

    private function major(int $minor): string
    {
        return BigDecimal::of($minor)->dividedBy(100, 2)->__toString();
    }

    private function minor(mixed $major): int
    {
        return BigDecimal::of((string) ($major ?? 0))->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    private function isAllowedCheckoutUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ($host === 'mercadopago.com' || str_ends_with($host, '.mercadopago.com') || str_ends_with($host, '.mercadopago.com.ar'));
    }
}
