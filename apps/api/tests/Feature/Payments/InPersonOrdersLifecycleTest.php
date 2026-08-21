<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\InPersonPaymentGatewayFactory;
use App\Data\Payments\ProviderOrder;
use App\Data\Payments\ProviderOrderTransaction;
use App\Data\Payments\ProviderTerminal;
use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentRequestPurpose;
use App\Enums\ReservationStatus;
use App\Exceptions\CommercialWorkflowException;
use App\Jobs\ProcessProviderOrderEventJob;
use App\Models\IntegrationConnection;
use App\Models\Payment;
use App\Models\PaymentTerminal;
use App\Models\Property;
use App\Models\ProviderPosLocation;
use App\Models\Reservation;
use App\Services\FolioService;
use App\Services\Payments\ApplyMercadoPagoOrder;
use App\Services\Payments\CancelInPersonOrder;
use App\Services\Payments\ExecuteInPersonRefund;
use App\Services\Payments\InitiateInPersonPayment;
use App\Services\Payments\ReceiveProviderWebhook;
use App\Services\Payments\ReconcileInPersonOrder;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakeInPersonPaymentGateway;
use Tests\TestCase;

class InPersonOrdersLifecycleTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_point_order_uses_staff_request_and_authoritative_processed_order_applies_exactly_once(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 50_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);

        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation,
            PaymentChannel::IntegratedTerminal,
            $terminal->id,
            PaymentRequestPurpose::FullOutstanding,
            null,
            null,
            $user->id,
            'point-create-command-0001',
        );
        $this->assertSame('queued', $attempt->state->value);
        $this->assertSame('staff_point', $attempt->paymentRequest->initiation_mode);
        $this->assertNull($attempt->paymentRequest->public_id);
        $this->assertNull($attempt->paymentRequest->access_token_hash);
        $this->assertCount(1, $fake->pointCreates);

        $created = $fake->fetchOrder($attempt->provider_order_id);
        $processed = $this->processed($created);
        $fake->orders[$created->externalReference] = $processed;
        app(ReconcileInPersonOrder::class)->handle($attempt->fresh());
        app(ReconcileInPersonOrder::class)->handle($attempt->fresh());

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentChannel::IntegratedTerminal, $payment->channel);
        $this->assertSame($processed->payments[0]->id, $payment->provider_reference);
        $this->assertSame($processed->id, data_get($payment->metadata, 'provider_order_id'));
        $this->assertSame('paid', $attempt->paymentRequest->fresh()->state->value);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseCount('settlement_entries', 1);
    }

    public function test_qr_payload_is_encrypted_then_removed_and_failed_scan_is_not_invented(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $pos = $this->pos($property->id, $connection);
        $reservation = $this->reservation($property->id, 25_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);

        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::Qr, $pos->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'qr-create-command-000001',
        );
        $this->assertSame('queued', $attempt->state->value);
        $this->assertNotNull($attempt->qr_data_ciphertext);
        $this->assertNotNull($attempt->qr_data_checksum);
        $raw = (string) \DB::table('payment_attempts')->where('id', $attempt->id)->value('qr_data_ciphertext');
        $this->assertStringNotContainsString('FAKE-INN-QR', $raw);

        $created = $fake->fetchOrder($attempt->provider_order_id);
        $processed = $this->processed($created);
        $fake->orders[$created->externalReference] = $processed;
        app(ReconcileInPersonOrder::class)->handle($attempt->fresh());
        $this->assertNull($attempt->fresh()->qr_data_ciphertext);
        $this->assertSame(1, Payment::query()->where('channel', 'qr')->count());

        $this->expectException(CommercialWorkflowException::class);
        app(ApplyMercadoPagoOrder::class)->handle($attempt->fresh(), new ProviderOrder(
            $processed->id, 'qr', $processed->providerAccount, $processed->externalReference,
            'failed', 'failed', $processed->amountMinor, $processed->currency, $processed->payments,
            externalPosId: $processed->externalPosId, qrMode: $processed->qrMode,
        ));
    }

    public function test_at_terminal_cancel_requires_device_and_late_approval_is_finance_mismatch(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 30_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'point-create-command-0002',
        );
        $created = $fake->fetchOrder($attempt->provider_order_id);
        $atTerminal = new ProviderOrder(
            $created->id, 'point', $created->providerAccount, $created->externalReference,
            'at_terminal', 'at_terminal', $created->amountMinor, $created->currency, $created->payments,
            terminalId: $created->terminalId,
        );
        $fake->orders[$created->externalReference] = $atTerminal;
        $result = app(CancelInPersonOrder::class)->handle($attempt->fresh(), 'point-cancel-command-0002');
        $this->assertSame('action_required', $result->state->value);
        $this->assertStringContainsString('physical terminal', $result->last_error);
        $this->assertCount(0, $fake->cancellations);

        $result->update(['state' => 'cancelled']);
        $late = $this->processed($atTerminal);
        $reconciled = app(ApplyMercadoPagoOrder::class)->handle($result->fresh(), $late);
        $this->assertSame('mismatched', $reconciled->state->value);
        $this->assertStringContainsString('Late provider approval', $reconciled->last_error);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_order_webhook_is_topic_dispatched_and_duplicate_delivery_is_append_only(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        Queue::fake();
        $body = json_encode([
            'type' => 'order', 'action' => 'order.processed', 'data' => ['id' => 'ORD01WEBHOOKTEST00000000000001'],
        ], JSON_THROW_ON_ERROR);
        $receiver = app(ReceiveProviderWebhook::class);
        $webhookKey = str_repeat('q', 32).substr(hash('sha256', $property->id), 0, 16);
        $first = $receiver->handle(
            $webhookKey,
            $body,
            ['x-request-id' => 'order-delivery-1'],
            ['type' => 'order', 'data.id' => 'ORD01WEBHOOKTEST00000000000001'],
        );
        $duplicate = $receiver->handle(
            $webhookKey,
            $body,
            ['x-request-id' => 'order-delivery-1'],
            ['type' => 'order', 'data.id' => 'ORD01WEBHOOKTEST00000000000001'],
        );

        $this->assertSame('order', $first->topic);
        $this->assertSame('duplicate', $duplicate->processing_state->value);
        $this->assertSame($first->id, $duplicate->duplicate_of_id);
        Queue::assertPushed(ProcessProviderOrderEventJob::class, 1);
        $this->assertDatabaseCount('provider_events', 2);
    }

    public function test_http_point_command_replays_once_and_cross_property_idor_is_denied(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 12_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $payload = ['terminal_id' => $terminal->id, 'purpose' => 'full_outstanding'];
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'http-point-create-0000001'];
        $this->postJson("/api/v1/reservations/{$reservation->id}/point-orders", $payload, $headers)
            ->assertCreated()->assertJsonPath('data.channel', 'integrated_terminal');
        $this->postJson("/api/v1/reservations/{$reservation->id}/point-orders", $payload, $headers)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertCount(1, $fake->pointCreates);
        $this->assertDatabaseCount('payment_attempts', 1);

        app(TenantContext::class)->set($tenant, $membership);
        $otherProperty = Property::factory()->create();
        $otherReservation = $this->reservation($otherProperty->id, 12_000);
        $this->postJson("/api/v1/reservations/{$otherReservation->id}/point-orders", $payload, [
            'X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'http-point-cross-property-1',
        ])->assertForbidden();
    }

    public function test_viewer_cannot_initiate_point_or_qr_order(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Viewer);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 12_000);
        $this->app->instance(InPersonPaymentGatewayFactory::class, new FakeInPersonPaymentGateway);

        $this->postJson("/api/v1/reservations/{$reservation->id}/point-orders", [
            'terminal_id' => $terminal->id, 'purpose' => 'full_outstanding',
        ], ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'viewer-point-denied-0001'])->assertForbidden();
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_disabled_or_replaced_target_cannot_start_an_order(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $replacement = $terminal->replicate();
        $replacement->provider_terminal_id = 'NEWLAND_N950__SBX0000002';
        $replacement->save();
        $terminal->update(['is_enabled' => false]);
        $replacement->update(['replaced_by_id' => $terminal->id]);
        $reservation = $this->reservation($property->id, 12_000);
        $this->app->instance(InPersonPaymentGatewayFactory::class, new FakeInPersonPaymentGateway);

        foreach ([$terminal, $replacement] as $target) {
            try {
                app(InitiateInPersonPayment::class)->handle(
                    $reservation, PaymentChannel::IntegratedTerminal, $target->id, PaymentRequestPurpose::FullOutstanding,
                    null, null, $user->id, 'disabled-replaced-'.$target->id,
                );
                $this->fail('A disabled or replaced terminal started an order.');
            } catch (CommercialWorkflowException $exception) {
                $this->assertStringContainsString('disabled, replaced', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_terminal_sync_and_pos_registration_refuse_cross_property_reassignment(): void
    {
        [$tenant, $property] = $this->tenantEnvironment(MembershipRole::Administrator);
        $connection = $this->connection($property->id);
        $connection->update(['property_id' => null]);
        $terminal = $this->terminal($property->id, $connection);
        $pos = $this->pos($property->id, $connection);
        $otherProperty = Property::factory()->create();
        $fake = new FakeInPersonPaymentGateway;
        $fake->terminals = [new ProviderTerminal($terminal->provider_terminal_id, 'PDV')];
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $headers = ['X-Tenant-ID' => $tenant->id];

        $this->postJson('/api/v1/payment-terminals/sync', [
            'integration_connection_id' => $connection->id,
            'property_id' => $otherProperty->id,
        ], $headers + ['Idempotency-Key' => 'cross-property-terminal-sync'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/provider-pos-locations', [
            'integration_connection_id' => $connection->id,
            'property_id' => $otherProperty->id,
            'provider_store_id' => 'STORE-2',
            'external_pos_id' => $pos->external_pos_id,
            'display_name' => 'Other property POS',
            'qr_mode' => 'dynamic',
        ], $headers + ['Idempotency-Key' => 'cross-property-pos-registration'])
            ->assertUnprocessable();

        $this->assertSame($property->id, $terminal->fresh()->property_id);
        $this->assertSame($property->id, $pos->fresh()->property_id);
    }

    public function test_authoritative_order_identity_mismatches_never_apply_money(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 14_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);

        $mutations = [
            fn (ProviderOrder $order): ProviderOrder => new ProviderOrder(...$this->orderArguments($order, providerAccount: 'OTHER-SELLER')),
            fn (ProviderOrder $order): ProviderOrder => new ProviderOrder(...$this->orderArguments($order, type: 'qr')),
            fn (ProviderOrder $order): ProviderOrder => new ProviderOrder(...$this->orderArguments($order, terminalId: 'OTHER-TERMINAL')),
            fn (ProviderOrder $order): ProviderOrder => new ProviderOrder(...$this->orderArguments($order, amountMinor: $order->amountMinor + 1)),
            fn (ProviderOrder $order): ProviderOrder => new ProviderOrder(...$this->orderArguments($order, currency: 'USD')),
        ];
        foreach ($mutations as $index => $mutation) {
            $attempt = app(InitiateInPersonPayment::class)->handle(
                $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
                null, null, $user->id, 'identity-mismatch-'.$index,
            );
            $created = $fake->fetchOrder($attempt->provider_order_id);
            try {
                app(ApplyMercadoPagoOrder::class)->handle($attempt, $mutation($this->processed($created)));
                $this->fail('A mismatched provider order was accepted.');
            } catch (CommercialWorkflowException) {
                $this->assertSame('mismatched', $attempt->fresh()->state->value);
            }
            $attempt->update(['state' => 'cancelled']);
        }

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_point_partial_refund_uses_order_identity_and_completes_once(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 20_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'point-refund-create-0001',
        );
        $created = $fake->fetchOrder($attempt->provider_order_id);
        $fake->orders[$created->externalReference] = $this->processed($created);
        $attempt = app(ReconcileInPersonOrder::class)->handle($attempt->fresh());
        $payment = $attempt->paymentRequest->fresh()->payment;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, $user->id);
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Guest cancellation', $user->id);

        $refund = app(ExecuteInPersonRefund::class)->handle($request, $user->id);
        $replayed = app(ExecuteInPersonRefund::class)->handle($request, $user->id);

        $this->assertSame('succeeded', $refund->state->value);
        $this->assertSame($refund->id, $replayed->id);
        $this->assertSame('order', $refund->provider_resource_type);
        $this->assertSame($attempt->provider_order_id, $refund->provider_order_id);
        $this->assertSame($attempt->provider_transaction_id, $refund->provider_transaction_id);
        $this->assertCount(1, $fake->refunds);
        $this->assertDatabaseCount('provider_refunds', 1);
        $this->assertDatabaseHas('reservation_changes', ['parent_change_id' => $request->id, 'type' => 'refund_completed', 'amount_minor' => 5_000]);
    }

    public function test_created_cancel_is_repeat_safe_and_recovers_timeout_after_remote_success(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 15_000);
        $fake = new FakeInPersonPaymentGateway;
        $fake->cancelThrowsAfterRemoteSuccess = true;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'point-timeout-create-0001',
        );

        $canceled = app(CancelInPersonOrder::class)->handle($attempt, 'point-timeout-cancel-0001');
        $replayed = app(CancelInPersonOrder::class)->handle($canceled->fresh(), 'point-timeout-cancel-0001');

        $this->assertSame('cancelled', $canceled->state->value);
        $this->assertSame($canceled->id, $replayed->id);
        $this->assertCount(1, $fake->cancellations);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_reordered_active_state_never_clears_action_required_or_downgrades_approved_money(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 18_000);
        $fake = new FakeInPersonPaymentGateway;
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'point-reorder-create-0001',
        );
        $created = $fake->fetchOrder($attempt->provider_order_id);
        $actionRequired = new ProviderOrder(
            $created->id, 'point', $created->providerAccount, $created->externalReference,
            'action_required', 'terminal_action_required', $created->amountMinor, $created->currency, $created->payments,
            terminalId: $created->terminalId,
        );
        $attempt = app(ApplyMercadoPagoOrder::class)->handle($attempt, $actionRequired);
        $stillRequired = app(ApplyMercadoPagoOrder::class)->handle($attempt, $created);
        $this->assertSame('action_required', $stillRequired->state->value);

        $approved = app(ApplyMercadoPagoOrder::class)->handle($stillRequired, $this->processed($created));
        $this->assertSame('approved', $approved->state->value);
        $regressed = app(ApplyMercadoPagoOrder::class)->handle($approved, $created);
        $this->assertSame('mismatched', $regressed->state->value);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
    }

    public function test_point_90_day_and_qr_360_day_refund_boundaries_are_enforced(): void
    {
        CarbonImmutable::setTestNow('2026-08-20T12:00:00Z');
        try {
            foreach ([
                [PaymentChannel::IntegratedTerminal, 90, true],
                [PaymentChannel::IntegratedTerminal, 91, false],
                [PaymentChannel::Qr, 360, true],
                [PaymentChannel::Qr, 361, false],
            ] as [$channel, $ageDays, $isEligible]) {
                [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
                $connection = $this->connection($property->id);
                $target = $channel === PaymentChannel::IntegratedTerminal
                    ? $this->terminal($property->id, $connection)
                    : $this->pos($property->id, $connection);
                $reservation = $this->reservation($property->id, 10_000);
                $fake = new FakeInPersonPaymentGateway;
                $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
                $attempt = app(InitiateInPersonPayment::class)->handle(
                    $reservation, $channel, $target->id, PaymentRequestPurpose::FullOutstanding,
                    null, null, $user->id, 'age-create-'.$channel->value,
                );
                $created = $fake->fetchOrder($attempt->provider_order_id);
                $fake->orders[$created->externalReference] = $this->processed($created);
                $attempt = app(ReconcileInPersonOrder::class)->handle($attempt);
                $attempt->update(['last_processed_at' => now()->subDays($ageDays)]);
                $payment = $attempt->paymentRequest->fresh()->payment;
                app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -10_000, $user->id);
                $request = app(RequestRefund::class)->handle($reservation, $payment, 10_000, 'Age boundary test', $user->id);

                $refund = app(ExecuteInPersonRefund::class)->handle($request, $user->id);
                if ($isEligible) {
                    $this->assertSame('succeeded', $refund->state->value);
                    $this->assertCount(1, $fake->refunds);
                } else {
                    $this->assertSame('failed', $refund->state->value);
                    $this->assertStringContainsString((string) ($channel === PaymentChannel::IntegratedTerminal ? 90 : 360), $refund->provider_reason);
                    $this->assertCount(0, $fake->refunds);
                }
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_point_refund_action_required_is_persisted_without_local_completion(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $connection = $this->connection($property->id);
        $terminal = $this->terminal($property->id, $connection);
        $reservation = $this->reservation($property->id, 20_000);
        $fake = new FakeInPersonPaymentGateway;
        $fake->refundStatus = 'action_required';
        $this->app->instance(InPersonPaymentGatewayFactory::class, $fake);
        $attempt = app(InitiateInPersonPayment::class)->handle(
            $reservation, PaymentChannel::IntegratedTerminal, $terminal->id, PaymentRequestPurpose::FullOutstanding,
            null, null, $user->id, 'point-action-refund-create-1',
        );
        $created = $fake->fetchOrder($attempt->provider_order_id);
        $fake->orders[$created->externalReference] = $this->processed($created);
        $attempt = app(ReconcileInPersonOrder::class)->handle($attempt);
        $payment = $attempt->paymentRequest->fresh()->payment;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, $user->id);
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Terminal action test', $user->id);

        $refund = app(ExecuteInPersonRefund::class)->handle($request, $user->id);

        $this->assertSame('processing', $refund->state->value);
        $this->assertTrue($refund->provider_action_required);
        $this->assertStringContainsString('terminal action', strtolower($refund->last_error));
        $this->assertDatabaseMissing('reservation_changes', ['parent_change_id' => $request->id, 'type' => 'refund_completed']);
    }

    private function connection(string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'name' => 'mercado-pago-orders', 'type' => 'payment', 'property_id' => $propertyId,
            'provider' => 'mercado_pago', 'product' => 'orders', 'external_account_id' => 'TEST-SELLER-ID',
            'environment' => 'sandbox', 'status' => 'connected', 'is_enabled' => true,
            'configuration' => [
                'charge_currency' => 'ARS',
                'webhook_key' => str_repeat('q', 32).substr(hash('sha256', $propertyId), 0, 16),
            ], 'secret_reference' => 'env:MP_ORDERS_TEST_TOKEN',
        ]);
        foreach (['payment.point_orders', 'payment.qr_orders'] as $capability) {
            $connection->connectionCapabilities()->create([
                'capability' => $capability, 'direction' => 'outbound', 'state' => 'enabled', 'configuration_version' => 1,
            ]);
        }

        return $connection;
    }

    private function terminal(string $propertyId, IntegrationConnection $connection): PaymentTerminal
    {
        return PaymentTerminal::query()->create([
            'property_id' => $propertyId, 'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'TEST-SELLER-ID',
            'provider_terminal_id' => 'NEWLAND_N950__SBX0000001', 'display_name' => 'Lobby Point',
            'operating_mode' => 'PDV', 'is_enabled' => true, 'health_state' => 'healthy',
        ]);
    }

    private function pos(string $propertyId, IntegrationConnection $connection): ProviderPosLocation
    {
        return ProviderPosLocation::query()->create([
            'property_id' => $propertyId, 'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'TEST-SELLER-ID',
            'provider_store_id' => 'STORE-1', 'external_pos_id' => 'INN-TEST-POS-1',
            'display_name' => 'Lobby QR', 'qr_mode' => 'dynamic', 'is_enabled' => true, 'health_state' => 'healthy',
        ]);
    }

    private function reservation(string $propertyId, int $amountMinor): Reservation
    {
        return Reservation::factory()->create([
            'property_id' => $propertyId, 'status' => ReservationStatus::Confirmed, 'currency' => 'ARS',
            'subtotal_minor' => $amountMinor, 'tax_minor' => 0, 'total_minor' => $amountMinor,
        ]);
    }

    private function processed(ProviderOrder $order): ProviderOrder
    {
        return new ProviderOrder(
            $order->id, $order->type, $order->providerAccount, $order->externalReference,
            'processed', 'processed', $order->amountMinor, $order->currency,
            [new ProviderOrderTransaction($order->payments[0]->id, $order->amountMinor, 'processed', 'accredited', $order->amountMinor)],
            terminalId: $order->terminalId, externalPosId: $order->externalPosId, qrMode: $order->qrMode,
        );
    }

    /** @return array<string, mixed> */
    private function orderArguments(
        ProviderOrder $order,
        ?string $type = null,
        ?string $providerAccount = null,
        ?int $amountMinor = null,
        ?string $currency = null,
        ?string $terminalId = null,
    ): array {
        return [
            'id' => $order->id,
            'type' => $type ?? $order->type,
            'providerAccount' => $providerAccount ?? $order->providerAccount,
            'externalReference' => $order->externalReference,
            'status' => $order->status,
            'statusDetail' => $order->statusDetail,
            'amountMinor' => $amountMinor ?? $order->amountMinor,
            'currency' => $currency ?? $order->currency,
            'payments' => $order->payments,
            'refunds' => $order->refunds,
            'terminalId' => $terminalId ?? $order->terminalId,
            'externalPosId' => $order->externalPosId,
            'qrMode' => $order->qrMode,
            'qrData' => $order->qrData,
            'createdAt' => $order->createdAt,
            'updatedAt' => $order->updatedAt,
        ];
    }
}
