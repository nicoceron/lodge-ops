<?php

namespace App\Console\Commands;

use App\Enums\MembershipRole;
use App\Enums\PaymentRequestPurpose;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\Payments\IssuePaymentRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class RunProviderComposeUat extends Command
{
    protected $signature = 'payments:provider-compose-uat';

    protected $description = 'Seed a local deterministic payment for the normal Compose HTTP/worker UAT.';

    public function handle(IssuePaymentRequest $issue, CreateProviderCheckout $checkout): int
    {
        if (! app()->environment('local')) {
            $this->error('The provider Compose UAT is restricted to the local environment.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $property = Property::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $membership = Membership::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('role', MembershipRole::Administrator)->firstOrFail();
        app(TenantContext::class)->set($tenant, $membership);
        $guest = Guest::query()->firstOrFail();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
            'source' => 'provider-compose-uat',
        ]);
        $webhookKey = 'compose-uat-'.Str::uuid()->toString();
        $connection = IntegrationConnection::query()->create([
            'name' => 'Mercado Pago deterministic Compose UAT '.Str::afterLast($webhookKey, '-'),
            'type' => 'payment',
            'configuration' => [
                'provider' => 'mercado_pago',
                'environment' => 'sandbox',
                'provider_account' => 'seller-compose-uat',
                'return_url_base' => config('app.url'),
                'webhook_key' => $webhookKey,
                'webhook_secret_reference' => 'env:MP_COMPOSE_UAT_WEBHOOK_SECRET',
                'transport' => 'deterministic_fixture',
                'fixture' => ['preference_id' => 'pref-compose-uat'],
            ],
            'secret_reference' => 'env:MP_COMPOSE_UAT_TOKEN',
        ]);
        $issued = $issue->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, $membership->user_id, now()->addHour());
        $attempt = $checkout->handle($issued->request, $connection);
        $providerPaymentId = 'compose-uat-payment-'.substr(str_replace('-', '', $attempt->id), -12);
        $configuration = $connection->configuration;
        data_set($configuration, 'fixture.payment', [
            'id' => $providerPaymentId,
            'collector_id' => 'seller-compose-uat',
            'external_reference' => $attempt->external_reference,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => '100.00',
            'currency_id' => 'ARS',
            'fee_details' => [['amount' => '1.00']],
            'transaction_details' => ['net_received_amount' => '98.00'],
        ]);
        $connection->update(['configuration' => $configuration]);

        $handoff = (string) Str::uuid();
        $handoffPath = sys_get_temp_dir().'/inn-provider-uat-'.$handoff.'.json';
        $descriptor = json_encode([
            'tenant_id' => $tenant->id,
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'payment_request_id' => $issued->request->id,
            'payment_token' => $issued->token,
            'attempt_id' => $attempt->id,
            'external_reference' => $attempt->external_reference,
            'provider_payment_id' => $providerPaymentId,
            'webhook_key' => $webhookKey,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($handoffPath, $descriptor, LOCK_EX) === false || ! chmod($handoffPath, 0600)) {
            @unlink($handoffPath);
            throw new RuntimeException('The provider UAT handoff could not be written securely.');
        }
        $this->line('UAT_HANDLE='.$handoff);

        return self::SUCCESS;
    }
}
