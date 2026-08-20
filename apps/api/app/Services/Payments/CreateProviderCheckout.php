<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\CheckoutRequest;
use App\Enums\PaymentAttemptState;
use App\Enums\PaymentRequestState;
use App\Exceptions\CommercialWorkflowException as DomainException;
use App\Models\ExchangeRate;
use App\Models\IntegrationConnection;
use App\Models\PaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\Reservation;
use App\Services\FolioService;
use App\Services\Integrations\EndpointKeyService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CreateProviderCheckout
{
    public function __construct(
        private readonly PaymentGatewayFactory $gateways,
        private readonly FolioService $folio,
        private readonly EndpointKeyService $endpointKeys,
    ) {}

    public function handle(PaymentRequest $request, IntegrationConnection $connection, bool $conversionAccepted = false, ?string $acceptedRateId = null): PaymentAttempt
    {
        $attempt = DB::transaction(function () use ($request, $connection, $conversionAccepted, $acceptedRateId): PaymentAttempt {
            $lockedRequest = PaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($lockedRequest->reservation_id);
            if (! in_array($lockedRequest->state, [PaymentRequestState::Open, PaymentRequestState::Processing], true) || $lockedRequest->expires_at->isPast()) {
                throw new DomainException('This payment request is no longer payable.');
            }
            if ($connection->type !== 'payment' || $connection->tenant_id !== $lockedRequest->tenant_id) {
                throw new DomainException('The payment connection is not available for this request.');
            }
            $provider = (string) data_get($connection->configuration, 'provider');
            $environment = (string) data_get($connection->configuration, 'environment', 'sandbox');
            $providerAccount = (string) data_get($connection->configuration, 'provider_account');
            if ($provider !== 'mercado_pago' || $providerAccount === '') {
                throw new DomainException('The Mercado Pago account is not configured.');
            }
            $existing = PaymentAttempt::query()
                ->where('payment_request_id', $lockedRequest->id)
                ->whereIn('state', [PaymentAttemptState::Creating, PaymentAttemptState::CheckoutReady, PaymentAttemptState::Pending])
                ->lockForUpdate()->first();
            if ($existing?->state === PaymentAttemptState::CheckoutReady && $existing->checkout_expires_at?->isPast()) {
                $existing->update([
                    'state' => PaymentAttemptState::Expired,
                    'last_error' => 'Hosted checkout expired before provider payment confirmation.',
                ]);
                $existing = null;
            }
            if ($existing !== null) {
                return $existing;
            }

            $outstanding = max(0, $this->folio->summary($reservation)['balance_minor']);
            if ($lockedRequest->source_amount_minor > $outstanding) {
                throw new DomainException('The outstanding balance changed; issue a replacement payment request.');
            }
            if ($lockedRequest->deposit !== null && $lockedRequest->deposit->payment_id !== null) {
                throw new DomainException('The requested deposit is already paid.');
            }

            [$chargeMinor, $chargeCurrency, $rate, $conversion] = $this->chargeMoney($lockedRequest, $connection, $conversionAccepted, $acceptedRateId);
            $attempt = PaymentAttempt::query()->create([
                'property_id' => $lockedRequest->property_id,
                'reservation_id' => $lockedRequest->reservation_id,
                'payment_request_id' => $lockedRequest->id,
                'deposit_id' => $lockedRequest->deposit_id,
                'integration_connection_id' => $connection->id,
                'provider' => $provider,
                'environment' => $environment,
                'provider_account' => $providerAccount,
                'external_reference' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'purpose' => $lockedRequest->purpose->value,
                'state' => PaymentAttemptState::Creating,
                'source_amount_minor' => $lockedRequest->source_amount_minor,
                'source_currency' => $lockedRequest->source_currency,
                'charge_amount_minor' => $chargeMinor,
                'charge_currency' => $chargeCurrency,
                'exchange_rate' => $rate,
                'conversion_snapshot' => $conversion,
                'payer_hash' => $reservation->primaryGuest?->email === null ? null : hash('sha256', strtolower($reservation->primaryGuest->email)),
            ]);
            $lockedRequest->update(['state' => PaymentRequestState::Processing]);

            return $attempt;
        }, 3);

        if ($attempt->state === PaymentAttemptState::CheckoutReady || $attempt->state === PaymentAttemptState::Pending) {
            return $attempt;
        }

        try {
            $configuration = $connection->configuration ?? [];
            $base = rtrim((string) data_get($configuration, 'return_url_base', config('app.url')), '/');
            $webhookKey = $this->endpointKeys->rawForOutbound($connection);
            $hosted = $this->gateways->for($connection)->createHostedCheckout(new CheckoutRequest(
                $attempt->external_reference,
                $attempt->idempotency_key,
                $attempt->charge_amount_minor,
                $attempt->charge_currency,
                'Reservation payment',
                "{$base}/pay/return/{$attempt->external_reference}",
                "{$base}/pay/return/{$attempt->external_reference}",
                "{$base}/pay/return/{$attempt->external_reference}",
                "{$base}/api/v1/payment-webhooks/{$webhookKey}",
                $attempt->reservation->primaryGuest?->email,
            ));
            $attempt->update([
                'state' => PaymentAttemptState::CheckoutReady,
                'provider_preference_id' => $hosted->preferenceId,
                'hosted_checkout_url' => $hosted->url,
                'checkout_expires_at' => $hosted->expiresAt,
                'attempt_count' => $attempt->attempt_count + 1,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $attempt->update([
                'attempt_count' => $attempt->attempt_count + 1,
                'error_count' => $attempt->error_count + 1,
                'last_error' => Str::limit($exception->getMessage(), 500),
            ]);
            throw $exception;
        }

        return $attempt->fresh();
    }

    /** @return array{int,string,?string,?array<string, mixed>} */
    private function chargeMoney(PaymentRequest $request, IntegrationConnection $connection, bool $conversionAccepted, ?string $acceptedRateId): array
    {
        $configuredCurrency = strtoupper((string) data_get($connection->configuration, 'charge_currency', 'ARS'));
        if ($request->source_currency === $configuredCurrency) {
            return [$request->source_amount_minor, $configuredCurrency, null, null];
        }
        if ($request->source_currency !== 'USD' || ! $conversionAccepted || $acceptedRateId === null) {
            throw new DomainException('USD reservations require explicit acceptance of an ARS conversion snapshot.');
        }
        $rate = ExchangeRate::query()
            ->whereKey($acceptedRateId)
            ->where(fn ($query) => $query->where('property_id', $request->property_id)->orWhereNull('property_id'))
            ->where('base_currency', 'USD')->where('quote_currency', 'ARS')
            ->where('effective_at', '<=', now())->where('effective_at', '>=', now()->subDay())
            ->lockForUpdate()->first();
        if ($rate === null) {
            throw new DomainException('No current USD to ARS conversion snapshot is available.');
        }
        $chargeMinor = BigDecimal::of($request->source_amount_minor)
            ->multipliedBy($rate->rate)->toScale(0, RoundingMode::HalfUp)->toInt();
        $snapshot = [
            'direction' => 'USD_ARS',
            'source' => $rate->source,
            'effective_at' => $rate->effective_at->toIso8601String(),
            'rate' => $rate->rate,
            'source_amount_minor' => $request->source_amount_minor,
            'charge_amount_minor' => $chargeMinor,
            'accepted_at' => now()->toIso8601String(),
        ];

        return [$chargeMinor, 'ARS', $rate->rate, $snapshot];
    }
}
