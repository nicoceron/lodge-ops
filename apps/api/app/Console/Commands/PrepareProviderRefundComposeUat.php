<?php

namespace App\Console\Commands;

use App\Enums\FolioLineType;
use App\Enums\ProviderRefundState;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Models\Tenant;
use App\Services\FolioService;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PrepareProviderRefundComposeUat extends Command
{
    protected $signature = 'payments:provider-compose-uat-refund {attempt : Payment-attempt UUID}';

    protected $description = 'Prepare a local deterministic partial refund for Finance recovery UAT.';

    public function handle(FolioService $folio, RequestRefund $requestRefund): int
    {
        if (! app()->environment('local')) {
            $this->error('The provider Compose UAT is restricted to the local environment.');

            return self::FAILURE;
        }

        $attempt = PaymentAttempt::withoutGlobalScopes()->findOrFail((string) $this->argument('attempt'));
        app(TenantContext::class)->set(Tenant::query()->findOrFail($attempt->tenant_id));
        $payment = Payment::query()->where('provider_reference', $attempt->provider_payment_id)->firstOrFail();
        $amountMinor = 2_000;
        $actorId = $attempt->paymentRequest->created_by;
        $folio->append($payment->reservation, FolioLineType::Adjustment, 'P3-06A partial refund credit', 1000, -$amountMinor, $actorId);
        $request = $requestRefund->handle($payment->reservation, $payment, $amountMinor, 'P3-06A provider recovery UAT', $actorId);
        $providerRefundId = 'compose-uat-refund-'.Str::lower(Str::random(12));
        $refund = ProviderRefund::query()->create([
            'property_id' => $attempt->property_id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $request->id,
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'source_amount_minor' => $amountMinor,
            'source_currency' => $payment->currency,
            'charge_amount_minor' => $amountMinor,
            'charge_currency' => $attempt->charge_currency,
            'idempotency_key' => (string) Str::uuid(),
            'provider_payment_id' => $payment->provider_reference,
            'state' => ProviderRefundState::Processing,
            'last_attempted_at' => now()->subHour(),
        ]);
        $connection = $attempt->integrationConnection;
        $configuration = $connection->configuration;
        data_set($configuration, 'fixture.refund', [
            'id' => $providerRefundId,
            'payment_id' => $payment->provider_reference,
            'status' => 'approved',
            'amount' => '20.00',
            'currency_id' => $attempt->charge_currency,
        ]);
        $connection->update(['configuration' => $configuration]);

        $this->line('REFUND_UAT='.json_encode([
            'provider_refund_model_id' => $refund->id,
            'provider_refund_id' => $providerRefundId,
            'payment_id' => $payment->id,
            'reservation_id' => $payment->reservation_id,
            'confirmation_number' => $payment->reservation->confirmation_number,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
