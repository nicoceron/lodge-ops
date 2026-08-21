<?php

namespace App\Console\Commands;

use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Membership;
use App\Models\PaymentTerminal;
use App\Models\Property;
use App\Models\ProviderPosLocation;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Payments\InitiateInPersonPayment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

class RunInPersonPaymentsComposeUat extends Command
{
    protected $signature = 'payments:in-person-compose-uat {--channel=point : point or qr}';

    protected $description = 'Prepare a local deterministic Point/QR Orders journey for normal HTTP and queue-worker UAT.';

    public function handle(
        InitiateInPersonPayment $initiate,
        IntegrationConnectionService $connections,
        EndpointKeyService $endpointKeys,
    ): int {
        if (! app()->environment('local')) {
            $this->error('The in-person provider Compose UAT is restricted to the local environment.');

            return self::FAILURE;
        }
        $channel = match ($this->option('channel')) {
            'point' => PaymentChannel::IntegratedTerminal,
            'qr' => PaymentChannel::Qr,
            default => null,
        };
        if ($channel === null) {
            $this->error('The channel must be point or qr.');

            return self::INVALID;
        }

        $tenant = Tenant::query()->where('slug', 'demo-lodge')->firstOrFail();
        $property = Property::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $membership = Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('role', MembershipRole::Administrator)->firstOrFail();
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
            'source' => 'in-person-compose-uat',
        ]);
        $providerAccount = 'seller-orders-compose-uat-'.Str::lower(Str::random(8));
        $connection = $connections->configure(
            'Mercado Pago Orders deterministic Compose UAT '.Str::lower(Str::random(8)),
            'payment',
            [
                'charge_currency' => 'ARS',
                'webhook_secret_reference' => 'env:MP_COMPOSE_UAT_WEBHOOK_SECRET',
                'transport' => 'deterministic_fixture',
                'fixture' => [
                    'orders_virtual' => true,
                    'currency' => 'ARS',
                ],
            ],
            'env:MP_COMPOSE_UAT_TOKEN',
            $property->id,
            'mercado_pago',
            'orders',
            $providerAccount,
            'sandbox',
            ['payment.point_orders', 'payment.qr_orders'],
        );
        $connection = $connections->enable($connection, $membership->user_id, 'Enable deterministic Orders Compose UAT connection.');
        $webhookKey = $endpointKeys->rotate($connection, 0, $membership->user_id, 'Issue deterministic Orders UAT callback key.')['key'];
        $terminal = PaymentTerminal::query()->create([
            'property_id' => $property->id,
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => $providerAccount,
            'provider_terminal_id' => 'NEWLAND_N950__SBX0000001',
            'display_name' => 'Virtual Point SBX0000001',
            'operating_mode' => 'PDV',
            'is_enabled' => true,
            'health_state' => 'virtual_test',
            'last_synced_at' => now(),
        ]);
        $pos = ProviderPosLocation::query()->create([
            'property_id' => $property->id,
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => $providerAccount,
            'provider_store_id' => 'STORE-COMPOSE-UAT',
            'external_pos_id' => 'INN-COMPOSE-UAT-'.strtoupper(Str::random(8)),
            'display_name' => 'Dynamic QR Compose UAT',
            'qr_mode' => 'dynamic',
            'is_enabled' => true,
            'health_state' => 'virtual_test',
            'last_synced_at' => now(),
        ]);
        $attempt = $initiate->handle(
            $reservation,
            $channel,
            $channel === PaymentChannel::IntegratedTerminal ? $terminal->id : $pos->id,
            PaymentRequestPurpose::FullOutstanding,
            null,
            null,
            $membership->user_id,
            'compose-orders-create-'.$reservation->id,
        );
        $orderId = $attempt->provider_order_id ?? throw new RuntimeException('Deterministic Orders UAT did not create an order identity.');
        $transactionId = $attempt->provider_transaction_id ?? throw new RuntimeException('Deterministic Orders UAT did not create a transaction identity.');
        $configuration = $connection->configuration;
        data_set($configuration, 'fixture.order', [
            'id' => $orderId,
            'type' => $channel === PaymentChannel::IntegratedTerminal ? 'point' : 'qr',
            'user_id' => $providerAccount,
            'external_reference' => $attempt->external_reference,
            'status' => 'processed',
            'status_detail' => 'processed',
            'currency' => 'ARS',
            'total_amount' => '100.00',
            'config' => $channel === PaymentChannel::IntegratedTerminal
                ? ['point' => ['terminal_id' => $terminal->provider_terminal_id]]
                : ['qr' => ['external_pos_id' => $pos->external_pos_id, 'mode' => 'dynamic']],
            'transactions' => ['payments' => [[
                'id' => $transactionId,
                'amount' => '100.00',
                'paid_amount' => '100.00',
                'status' => 'processed',
                'status_detail' => 'accredited',
            ]]],
        ]);
        $connection->update(['configuration' => $configuration]);

        $handoff = (string) Str::uuid();
        $handoffPath = sys_get_temp_dir().'/inn-in-person-uat-'.$handoff.'.json';
        $descriptor = json_encode([
            'tenant_id' => $tenant->id,
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'attempt_id' => $attempt->id,
            'channel' => $channel->value,
            'provider_order_id' => $orderId,
            'provider_transaction_id' => $transactionId,
            'webhook_key' => $webhookKey,
        ], JSON_THROW_ON_ERROR);
        if (file_put_contents($handoffPath, $descriptor, LOCK_EX) === false || ! chmod($handoffPath, 0600)) {
            @unlink($handoffPath);
            throw new RuntimeException('The in-person UAT handoff could not be written securely.');
        }
        $this->line('IN_PERSON_UAT_HANDLE='.$handoff);

        return self::SUCCESS;
    }
}
