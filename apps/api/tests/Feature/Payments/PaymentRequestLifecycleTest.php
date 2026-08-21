<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderPayment;
use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Enums\ProviderEventState;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException;
use App\Models\Deposit;
use App\Models\ExchangeRate;
use App\Models\IntegrationConnection;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\ProviderEvent;
use App\Models\Reservation;
use App\Services\FolioService;
use App\Services\IntegrationConnectionService;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\Payments\ExecuteProviderRefund;
use App\Services\Payments\IssuePaymentRequest;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\ProcessProviderEvent;
use App\Services\PaymentService;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class PaymentRequestLifecycleTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_staff_issues_hashed_link_and_rotation_invalidates_the_old_token(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Sales);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => 100_000,
            'tax_minor' => 0,
            'total_minor' => 100_000,
        ]);

        $response = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/reservations/{$reservation->id}/payment-requests", [
            'purpose' => 'authorized_partial',
            'amount_minor' => 40_000,
        ], ['Idempotency-Key' => 'issue-payment-request-0001'])->assertCreated();
        $url = $response->json('access.url');
        $token = str($url)->afterLast('/')->toString();
        app(TenantContext::class)->set($tenant);
        $record = PaymentRequest::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $record->access_token_hash);
        $this->assertStringNotContainsString($token, json_encode($record->getAttributes(), JSON_THROW_ON_ERROR));

        $this->get('/pay/'.$token)->assertOk()->assertSee('Pay securely with Mercado Pago');
        $rotated = $this->withHeader('X-Tenant-ID', $tenant->id)->postJson("/api/v1/payment-requests/{$record->id}/rotate", [], ['Idempotency-Key' => 'rotate-payment-request-01'])->assertOk();
        $newToken = str($rotated->json('access.url'))->afterLast('/')->toString();
        $this->get('/pay/'.$token)->assertNotFound();
        $this->get('/pay/'.$newToken)->assertOk();
        $this->assertNotSame($token, $newToken);
    }

    public function test_connection_charge_currency_can_be_used_without_conversion(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'COP',
            'subtotal_minor' => 1_000_000,
            'tax_minor' => 0,
            'total_minor' => 1_000_000,
        ]);
        $connection = $this->connection();
        $configuration = $connection->configuration;
        $configuration['charge_currency'] = 'COP';
        $connection->update(['configuration' => $configuration]);
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());

        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection->fresh());

        $this->assertSame('COP', $issued->request->charge_currency);
        $this->assertSame('COP', $attempt->charge_currency);
        $this->assertSame(1_000_000, $attempt->charge_amount_minor);
        $this->assertNull($attempt->exchange_rate);
        $this->assertNull($attempt->conversion_snapshot);
    }

    public function test_payment_connection_selection_prefers_enabled_property_scope_then_global_and_ignores_disabled_or_revoked_rows(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $connections = app(IntegrationConnectionService::class);
        app(TenantContext::class)->set($tenant);
        $global = $connections->configure(
            'Global MP', 'payment', ['return_url_base' => 'https://inn.test', 'charge_currency' => 'ARS'],
            'env:GLOBAL_MP', null, 'mercado_pago', 'checkout_pro', 'global-account', 'sandbox', ['payment.hosted_checkout'],
        );
        $global = $connections->enable($global, $user->id, 'Enable global payment connection.');
        app(TenantContext::class)->set($tenant, $membership);
        $disabled = $connections->configure(
            'Disabled property MP', 'payment', ['return_url_base' => 'https://inn.test', 'charge_currency' => 'ARS'],
            'env:DISABLED_MP', $property->id, 'mercado_pago', 'checkout_pro', 'a-disabled-account', 'sandbox', ['payment.hosted_checkout'],
        );
        $revoked = $connections->configure(
            'Revoked property MP', 'payment', ['return_url_base' => 'https://inn.test', 'charge_currency' => 'ARS'],
            'env:REVOKED_MP', $property->id, 'mercado_pago', 'checkout_pro', 'b-revoked-account', 'sandbox', ['payment.hosted_checkout'],
        );
        $revoked = $connections->enable($revoked, $user->id, 'Enable before revoke.');
        $connections->revoke($revoked, $user->id, 'Revoke property payment connection.');
        $exact = $connections->configure(
            'Exact property MP', 'payment', ['return_url_base' => 'https://inn.test', 'charge_currency' => 'ARS'],
            'env:EXACT_MP', $property->id, 'mercado_pago', 'checkout_pro', 'c-exact-account', 'sandbox', ['payment.hosted_checkout'],
        );
        $exact = $connections->enable($exact, $user->id, 'Enable exact property connection.');
        $resolver = app(PaymentConnectionResolver::class);
        $this->assertSame($exact->id, $resolver->forProperty($tenant->id, $property->id)->id);

        $connections->disable($exact, $user->id, 'Disable exact property connection.');
        $this->assertSame($global->id, $resolver->forProperty($tenant->id, $property->id)->id);
        $connections->disable($global, $user->id, 'Disable global fallback.');
        $this->expectException(CommercialWorkflowException::class);
        $resolver->forProperty($tenant->id, $property->id);
        $this->assertFalse($disabled->is_enabled);
    }

    public function test_approved_provider_lookup_posts_one_payment_folio_effect_and_deposit(): void
    {
        [, $property] = $this->tenantEnvironment();
        Queue::fake();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => 100_000,
            'tax_minor' => 0,
            'total_minor' => 100_000,
        ]);
        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => 'due',
            'currency' => 'ARS',
            'amount_minor' => 50_000,
        ]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::Deposit, $deposit->id, null, auth()->id());
        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        $fake->payments['mp-100'] = new ProviderPayment(
            'mp-100', $attempt->external_reference, 'approved', 'accredited', 50_000, 'ARS', 'seller-1',
            ['gross_minor' => 50_000, 'fee_minor' => 2_000, 'net_minor' => 48_000],
        );
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => 'seller-1',
            'delivery_id' => 'delivery-100',
            'topic' => 'payment',
            'event_type' => 'payment',
            'action' => 'payment.updated',
            'resource_id' => 'mp-100',
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', 'delivery-100'),
            'private_payload' => ['data' => ['id' => 'mp-100']],
        ]);

        app(ProcessProviderEvent::class)->handle($event);
        app(ProcessProviderEvent::class)->handle($event->fresh());

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['origin' => PaymentOrigin::Provider->value, 'provider_reference' => 'mp-100', 'amount_minor' => 50_000]);
        $this->assertDatabaseHas('folio_lines', ['payment_id' => $issued->request->fresh()->payment_id, 'amount_minor' => -50_000]);
        $this->assertDatabaseHas('deposits', ['id' => $deposit->id, 'status' => 'paid']);
        $this->assertSame(PaymentRequestState::Paid, $issued->request->fresh()->state);
        $this->assertDatabaseHas('settlement_entries', ['source_id' => 'mp-100', 'reconciliation_state' => 'matched']);
    }

    public function test_provider_amount_mismatch_never_posts_money(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS', 'total_minor' => 20_000]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        $fake->payments['mp-bad'] = new ProviderPayment('mp-bad', $attempt->external_reference, 'approved', null, 19_999, 'ARS', 'seller-1');
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id, 'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-1',
            'delivery_id' => 'delivery-bad', 'resource_id' => 'mp-bad', 'signature_valid' => true, 'received_at' => now(),
            'processing_state' => ProviderEventState::Received, 'raw_body_checksum' => hash('sha256', 'delivery-bad'),
        ]);

        app(ProcessProviderEvent::class)->handle($event);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('mismatched', $attempt->fresh()->state->value);
        $this->assertSame('mismatched', $event->fresh()->processing_state->value);
    }

    public function test_expired_hosted_checkout_is_replaced_instead_of_reused(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => 20_000, 'tax_minor' => 0, 'total_minor' => 20_000,
        ]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $expired = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        $expired->update(['checkout_expires_at' => now()->subMinute()]);

        $replacement = app(CreateProviderCheckout::class)->handle($issued->request->fresh(), $connection);

        $this->assertNotSame($expired->id, $replacement->id);
        $this->assertSame('expired', $expired->fresh()->state->value);
        $this->assertSame('checkout_ready', $replacement->state->value);
        $this->assertCount(2, $fake->checkouts);
    }

    public function test_manual_settlement_winning_the_race_prevents_a_second_provider_payment(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => 20_000, 'tax_minor' => 0, 'total_minor' => 20_000,
        ]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'cash',
            'amount_minor' => 20_000,
        ], auth()->id(), true);
        $fake->payments['mp-lost-race'] = new ProviderPayment(
            'mp-lost-race', $attempt->external_reference, 'approved', null, 20_000, 'ARS', 'seller-1',
        );
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id, 'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-1',
            'delivery_id' => 'delivery-lost-race', 'resource_id' => 'mp-lost-race', 'signature_valid' => true, 'received_at' => now(),
            'processing_state' => ProviderEventState::Received, 'raw_body_checksum' => hash('sha256', 'delivery-lost-race'),
        ]);

        app(ProcessProviderEvent::class)->handle($event);

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(PaymentOrigin::Manual, Payment::query()->sole()->origin);
        $this->assertSame('mismatched', $attempt->fresh()->state->value);
        $this->assertSame('mismatched', $event->fresh()->processing_state->value);
        $this->assertNull($issued->request->fresh()->payment_id);
    }

    public function test_two_provider_checkouts_can_never_satisfy_one_request_twice(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => 20_000, 'tax_minor' => 0, 'total_minor' => 20_000,
        ]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $firstAttempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        $firstAttempt->update(['checkout_expires_at' => now()->subMinute()]);
        $secondAttempt = app(CreateProviderCheckout::class)->handle($issued->request->fresh(), $connection);

        foreach ([[$firstAttempt, 'mp-first'], [$secondAttempt, 'mp-second']] as $index => [$attempt, $providerId]) {
            $fake->payments[$providerId] = new ProviderPayment(
                $providerId, $attempt->external_reference, 'approved', null, 20_000, 'ARS', 'seller-1',
            );
            $event = ProviderEvent::query()->create([
                'integration_connection_id' => $connection->id, 'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-1',
                'delivery_id' => 'delivery-double-'.$index, 'resource_id' => $providerId, 'signature_valid' => true, 'received_at' => now(),
                'processing_state' => ProviderEventState::Received, 'raw_body_checksum' => hash('sha256', 'delivery-double-'.$index),
            ]);
            app(ProcessProviderEvent::class)->handle($event);
        }

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['provider_reference' => 'mp-first']);
        $this->assertSame('mismatched', $secondAttempt->fresh()->state->value);
        $this->assertDatabaseCount('settlement_entries', 1);
    }

    public function test_usd_checkout_requires_a_current_explicitly_accepted_ars_snapshot(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'USD',
            'subtotal_minor' => 12_345, 'tax_minor' => 0, 'total_minor' => 12_345,
        ]);
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $connection = $this->connection();
        try {
            app(CreateProviderCheckout::class)->handle($issued->request, $connection, false);
            $this->fail('Conversion consent must be explicit.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }
        $rate = ExchangeRate::query()->create([
            'property_id' => $property->id, 'base_currency' => 'USD', 'quote_currency' => 'ARS',
            'rate' => '1000.0050000000', 'source' => 'test-central-bank', 'effective_at' => now(),
        ]);
        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection, true, $rate->id);
        $this->assertSame(12_345_062, $attempt->charge_amount_minor);
        $this->assertSame('ARS', $attempt->charge_currency);
        $this->assertSame('test-central-bank', data_get($attempt->conversion_snapshot, 'source'));
        $this->assertNotNull(data_get($attempt->conversion_snapshot, 'accepted_at'));
    }

    public function test_provider_refund_completes_inn_refund_only_after_remote_success(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => 20_000, 'tax_minor' => 0, 'total_minor' => 20_000,
        ]);
        $connection = $this->connection();
        $issued = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id());
        $attempt = app(CreateProviderCheckout::class)->handle($issued->request, $connection);
        $fake->payments['mp-refund-source'] = new ProviderPayment('mp-refund-source', $attempt->external_reference, 'approved', null, 20_000, 'ARS', 'seller-1');
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id, 'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-1',
            'delivery_id' => 'delivery-refund', 'resource_id' => 'mp-refund-source', 'signature_valid' => true, 'received_at' => now(),
            'processing_state' => ProviderEventState::Received, 'raw_body_checksum' => hash('sha256', 'delivery-refund'),
        ]);
        app(ProcessProviderEvent::class)->handle($event);
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, auth()->id());
        $payment = $issued->request->fresh()->payment;
        $refundRequest = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Guest cancellation', auth()->id());

        $execution = app(ExecuteProviderRefund::class)->handle($refundRequest, auth()->id());
        $this->assertSame('succeeded', $execution->state->value);
        $this->assertCount(1, $fake->refunds);
        $this->assertSame(5_000, $fake->refunds[0]->amountMinor);
        $this->assertDatabaseHas('reservation_changes', ['parent_change_id' => $refundRequest->id, 'type' => 'refund_completed', 'amount_minor' => 5_000]);
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
    }

    private function connection(): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'name' => 'mercado-pago-argentina',
            'type' => 'payment',
            'provider' => 'mercado_pago', 'product' => 'checkout_pro', 'external_account_id' => 'seller-1', 'environment' => 'sandbox',
            'status' => 'connected', 'is_enabled' => true, 'capabilities' => ['payment.hosted_checkout'],
            'configuration' => [
                'return_url_base' => 'https://inn.test', 'webhook_key' => str_repeat('w', 48),
            ],
            'secret_reference' => 'env:MP_TEST_TOKEN',
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.hosted_checkout', 'direction' => 'outbound', 'state' => 'enabled', 'configuration_version' => 1,
        ]);

        return $connection;
    }
}
