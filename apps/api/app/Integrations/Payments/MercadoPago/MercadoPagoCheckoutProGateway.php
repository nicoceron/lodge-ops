<?php

namespace App\Integrations\Payments\MercadoPago;

use App\Contracts\Payments\PaymentGateway;
use App\Data\Payments\CheckoutRequest;
use App\Data\Payments\ExactJsonDecimal;
use App\Data\Payments\HostedCheckout;
use App\Data\Payments\ProviderDispute;
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
        private readonly ?bool $useSandboxCheckoutUrl = null,
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
        $useSandboxCheckoutUrl = $this->useSandboxCheckoutUrl ?? $this->environment !== 'production';
        $url = data_get($payload, $useSandboxCheckoutUrl ? 'sandbox_init_point' : 'init_point')
            ?? data_get($payload, 'init_point');
        if (! is_string($id) || ! is_string($url) || ! $this->isAllowedCheckoutUrl($url)) {
            throw new RuntimeException('Mercado Pago returned an invalid hosted checkout.');
        }

        return new HostedCheckout($id, $url, isset($payload['expiration_date_to']) ? CarbonImmutable::parse($payload['expiration_date_to']) : null);
    }

    public function fetchPayment(string $providerPaymentId): ProviderPayment
    {
        if ($this->providerAccount === '') {
            throw new RuntimeException('The configured Mercado Pago account identity is missing.');
        }
        $payload = $this->transport->request('GET', '/v1/payments/'.rawurlencode($providerPaymentId));

        $collector = data_get($payload, 'collector_id');
        $account = is_string($collector) || is_int($collector) ? (string) $collector : '';
        $feeMinor = $this->sumMinor(collect(data_get($payload, 'fee_details', []))->pluck('amount')->all());
        $netValue = data_get($payload, 'transaction_details.net_received_amount');

        return new ProviderPayment(
            (string) data_get($payload, 'id'),
            (string) data_get($payload, 'external_reference'),
            (string) data_get($payload, 'status'),
            data_get($payload, 'status_detail'),
            $this->minor(data_get($payload, 'transaction_amount')),
            (string) data_get($payload, 'currency_id'),
            $account,
            [
                'gross_minor' => $this->minor(data_get($payload, 'transaction_amount')),
                'fee_minor' => $feeMinor,
                'tax_minor' => $this->nullableMinor(data_get($payload, 'taxes_amount')),
                'withholding_minor' => $this->chargeDetailMinor($payload, ['withholding']),
                'refunded_minor' => $this->nullableMinor(data_get($payload, 'transaction_amount_refunded')),
                'chargeback_minor' => null,
                'net_minor' => $netValue === null ? $this->minor(data_get($payload, 'transaction_amount')) - $feeMinor : $this->minor($netValue),
                'fact_source' => 'payment_lookup',
                'expected_release_at' => $this->nullableString(data_get($payload, 'money_release_date')),
                'settlement_identity' => null,
                'settlement_date' => null,
                'settlement_status' => null,
                'payout_identity' => null,
                'payout_date' => null,
                'payout_status' => null,
            ],
        );
    }

    public function fetchDispute(string $providerDisputeId): ProviderDispute
    {
        $payload = $this->transport->request('GET', '/v1/chargebacks/'.rawurlencode($providerDisputeId));
        $paymentId = collect(data_get($payload, 'payments', []))->first();
        if ((! is_string($paymentId) && ! is_int($paymentId)) || $paymentId === '') {
            throw new RuntimeException('Mercado Pago returned a chargeback without a payment identity.');
        }
        $payment = $this->fetchPayment((string) $paymentId);

        return new ProviderDispute(
            (string) data_get($payload, 'id'),
            (string) $paymentId,
            $payment->status,
            $payment->statusDetail,
            $this->minor(data_get($payload, 'amount')),
            (string) data_get($payload, 'currency'),
            $payment->providerAccount,
            $this->nullableString(data_get($payload, 'reason')),
            is_bool(data_get($payload, 'coverage_applied')) ? data_get($payload, 'coverage_applied') : null,
            is_bool(data_get($payload, 'documentation_required')) ? data_get($payload, 'documentation_required') : null,
            data_get($payload, 'date_documentation_deadline') ? CarbonImmutable::parse(data_get($payload, 'date_documentation_deadline')) : null,
            data_get($payload, 'date_created') ? CarbonImmutable::parse(data_get($payload, 'date_created')) : null,
            data_get($payload, 'date_last_updated') ? CarbonImmutable::parse(data_get($payload, 'date_last_updated')) : null,
        );
    }

    public function refund(ProviderRefundRequest $request): ProviderRefund
    {
        $payload = $this->transport->request('POST', '/v1/payments/'.rawurlencode($request->providerPaymentId).'/refunds', [
            'amount' => $this->major($request->amountMinor),
        ], ['X-Idempotency-Key' => $request->idempotencyKey]);
        $payment = $this->fetchPayment($request->providerPaymentId);

        return $this->normalizeRefund($payload, $request->currency, $payment->providerAccount);
    }

    public function fetchRefund(string $providerPaymentId, string $providerRefundId): ProviderRefund
    {
        $payload = $this->transport->request('GET', '/v1/payments/'.rawurlencode($providerPaymentId).'/refunds/'.rawurlencode($providerRefundId));
        $payment = $this->fetchPayment($providerPaymentId);
        $currency = data_get($payload, 'currency_id');
        if (! is_string($currency) || $currency === '') {
            $currency = $payment->currency;
        }

        return $this->normalizeRefund($payload, $currency, $payment->providerAccount);
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
        $timestampValue = (int) $timestamp;
        $timestampSeconds = strlen($timestamp) > 10 ? intdiv($timestampValue, 1000) : $timestampValue;
        if (abs(now()->timestamp - $timestampSeconds) > $request->toleranceSeconds) {
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

    private function normalizeRefund(array $payload, string $currency, string $providerAccount): ProviderRefund
    {
        $id = data_get($payload, 'id');
        $paymentId = data_get($payload, 'payment_id');
        if ((! is_string($id) && ! is_int($id)) || (! is_string($paymentId) && ! is_int($paymentId))) {
            throw new RuntimeException('Mercado Pago returned a malformed refund.');
        }

        return new ProviderRefund((string) $id, (string) $paymentId, (string) data_get($payload, 'status'), $this->minor(data_get($payload, 'amount')), $currency, $providerAccount);
    }

    private function major(int $minor): ExactJsonDecimal
    {
        return new ExactJsonDecimal(BigDecimal::of($minor)->dividedBy(100, 2)->__toString());
    }

    private function minor(mixed $major): int
    {
        return BigDecimal::of((string) ($major ?? 0))->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
    }

    private function nullableMinor(mixed $major): ?int
    {
        return $major === null || $major === '' ? null : $this->minor($major);
    }

    /** @param list<string> $needles */
    private function chargeDetailMinor(array $payload, array $needles): ?int
    {
        $amounts = collect(data_get($payload, 'charges_details', []))
            ->filter(function ($detail) use ($needles): bool {
                $label = strtolower((string) data_get($detail, 'name', data_get($detail, 'type', '')));

                return collect($needles)->contains(fn (string $needle): bool => str_contains($label, $needle));
            })
            ->pluck('amount');

        return $amounts->isEmpty() ? null : $this->sumMinor($amounts->all());
    }

    /** @param iterable<mixed> $amounts */
    private function sumMinor(iterable $amounts): int
    {
        $total = BigDecimal::of(0);
        foreach ($amounts as $amount) {
            $total = $total->plus(BigDecimal::of((string) ($amount ?? 0)));
        }

        return $this->minor($total->__toString());
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isAllowedCheckoutUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && ($host === 'mercadopago.com'
            || $host === 'mercadopago.com.ar'
            || $host === 'mercadopago.com.co'
            || str_ends_with($host, '.mercadopago.com')
            || str_ends_with($host, '.mercadopago.com.ar')
            || str_ends_with($host, '.mercadopago.com.co'));
    }
}
