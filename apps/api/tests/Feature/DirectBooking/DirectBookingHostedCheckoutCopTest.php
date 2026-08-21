<?php

namespace Tests\Feature\DirectBooking;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Enums\AllocationStatus;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\FolioLineType;
use App\Enums\ProviderEventState;
use App\Enums\ReservationStatus;
use App\Integrations\Payments\MercadoPago\DefaultPaymentGatewayFactory;
use App\Integrations\Payments\MercadoPago\MercadoPagoCheckoutProGateway;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\FolioLine;
use App\Models\IntegrationConnection;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Services\DirectBooking\DirectBookingLaunchReadinessEvaluator;
use App\Services\Payments\ProcessProviderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class DirectBookingHostedCheckoutCopTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        putenv('DIRECT_BOOKING_COP_MP_TOKEN=cop-test-access-token');
        putenv('DIRECT_BOOKING_COP_MP_WEBHOOK=cop-test-webhook-secret');
    }

    protected function tearDown(): void
    {
        putenv('DIRECT_BOOKING_COP_MP_TOKEN');
        putenv('DIRECT_BOOKING_COP_MP_WEBHOOK');
        parent::tearDown();
    }

    public function test_hosted_checkout_uses_cop_checkout_pro_and_rejects_pan_fields(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $connection = $this->copHostedConnection($property->id);
        $this->assertSame([], app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting)->blockingReasons);
        $this->assertInstanceOf(DefaultPaymentGatewayFactory::class, app(PaymentGatewayFactory::class));
        $this->assertInstanceOf(MercadoPagoCheckoutProGateway::class, app(PaymentGatewayFactory::class)->for($connection));
        $this->assertNotInstanceOf(FakePaymentGateway::class, app(PaymentGatewayFactory::class));

        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        [$reference, $auth, $held] = $this->heldOrder($base, $this->stay($categoryItem->public_key));

        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
            'card_number' => '5031755734530604',
            'cvv' => '123',
            'expiry' => '11/30',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $checkout = $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.state', 'payment_pending')
            ->assertJsonPath('data.method', 'hosted_checkout');

        $checkoutUrl = $checkout->json('data.checkout_url');
        $this->assertIsString($checkoutUrl);
        $this->assertSame('https', parse_url($checkoutUrl, PHP_URL_SCHEME));
        $this->assertStringContainsString('mercadopago.com', (string) parse_url($checkoutUrl, PHP_URL_HOST));

        app(TenantContext::class)->set($tenant, $membership);
        $attempt = PaymentAttempt::query()->sole();
        $this->assertSame('COP', $attempt->charge_currency);
        $this->assertSame('checkout_pro', $connection->fresh()->product);
        $this->assertSame('MCO', data_get($connection->fresh()->configuration, 'site'));
        $this->assertSame('COP', data_get($connection->fresh()->configuration, 'charge_currency'));
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(ReservationStatus::Hold, DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail()->reservation->status);
    }

    public function test_status_and_return_url_do_not_confirm_or_create_a_payment(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        [$reference, $auth, $held] = $this->heldOrder($base, $this->stay($categoryItem->public_key));
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        app(TenantContext::class)->set($tenant, $membership);
        $externalReference = PaymentAttempt::query()->value('external_reference');

        $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'payment_pending');
        $this->get('/pay/return/'.$externalReference)
            ->assertOk()
            ->assertSee('never records money');

        $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'payment_pending');
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, FolioLine::query()->where('type', FolioLineType::Payment)->count());
        $this->assertSame(ReservationStatus::Hold, DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail()->reservation->status);
    }

    public function test_failed_checkout_is_visible_and_retry_does_not_confirm(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $connection = $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        [$reference, $auth, $held] = $this->heldOrder($base, $this->stay($categoryItem->public_key));
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        app(TenantContext::class)->set($tenant, $membership);
        $attempt = PaymentAttempt::query()->sole();
        $this->storeRejectedPayment($connection, $attempt);
        app(ProcessProviderEvent::class)->handle($this->providerEvent($connection, 'cop-rejected-1', 'cop-rejected-delivery'));

        $failed = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'payment_failed');
        $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $retry = $this->postJson($base."/orders/{$reference}/payments/retry", [
            'expected_state_version' => $failed->json('data.state_version'),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.state', 'payment_pending');

        $this->assertSame('https', parse_url((string) $retry->json('data.checkout_url'), PHP_URL_SCHEME));
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(ReservationStatus::Hold, DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail()->reservation->status);
    }

    public function test_worker_apply_confirms_with_stable_number_and_opaque_documents(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $connection = $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        [$reference, $auth, $held] = $this->heldOrder($base, $this->stay($categoryItem->public_key));
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        app(TenantContext::class)->set($tenant, $membership);
        $attempt = PaymentAttempt::query()->sole();
        $this->storeApprovedPayment($connection, $attempt);
        app(ProcessProviderEvent::class)->handle($this->providerEvent($connection, 'cop-approved-1', 'cop-approved-delivery'));

        $status = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'confirmed');
        $confirmation = $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'confirmed')
            ->assertJsonPath('data.links.guest_portal.entry_path', '/guest/stay');

        $number = $confirmation->json('data.confirmation_number');
        $this->assertIsString($number);
        $this->assertNotSame('', $number);
        $this->assertSame($number, $status->json('data.confirmation_number'));
        $this->assertSame($number, $this->getJson($base."/orders/{$reference}/confirmation", $auth)->json('data.confirmation_number'));

        $documents = collect($confirmation->json('data.documents'));
        $this->assertGreaterThanOrEqual(2, $documents->count());
        $this->assertNotEmpty($documents->firstWhere('kind', 'payment_receipt'));
        $this->assertNotEmpty($documents->firstWhere('kind', 'reservation_confirmation'));
        foreach ($documents as $document) {
            $path = $document['download_path'];
            $this->assertIsString($path);
            $this->assertStringStartsWith('/api/v1/direct-booking/properties/', $path);
            $this->assertStringNotContainsString('/app/storage', $path);
            $this->assertStringNotContainsString('DIRECT_BOOKING', $path);
        }

        $receipt = $documents->firstWhere('kind', 'payment_receipt');
        $this->get($receipt['download_path'], $auth)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get($receipt['download_path'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->get('/app/storage/app/private/'.$receipt['document_reference'].'.pdf', $auth)
            ->assertNotFound();
        $this->assertStringNotContainsString('/app/storage', (string) $confirmation->getContent());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_manual_bank_transfer_evidence_does_not_confirm_or_apply_folio(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        [$reference, $auth, $held] = $this->heldOrder($base, $this->stay($categoryItem->public_key));

        $checkout = $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'manual_bank_transfer',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_manual_payment')
            ->assertJsonPath('data.manual_payment_instructions.currency', 'COP');

        $this->post($base."/orders/{$reference}/manual-payment-evidence", [
            'expected_state_version' => $checkout->json('data.state_version'),
            'evidence' => UploadedFile::fake()->image('receipt.png', 20, 20),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.state', 'evidence_pending');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertContains(
            DirectBookingOrder::query()->where('public_reference', $reference)->value('state')->value,
            ['evidence_pending', 'finance_review'],
        );
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, FolioLine::query()->where('type', FolioLineType::Payment)->count());
        $this->assertSame(ReservationStatus::Hold, DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail()->reservation->status);
        $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_recover_rotates_session_and_does_not_confirm(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reference = $begun->json('data.order_reference');
        $sessionToken = $begun->json('data.session_token');
        $recoveryToken = $begun->json('data.recovery_token');
        $auth = ['Authorization' => 'Bearer '.$sessionToken];
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $held = $this->postJson($base."/orders/{$reference}/hold", $this->holdBody($quoted), $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        app(TenantContext::class)->set($tenant, $membership);
        $order = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $order->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        $order->reservation->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        Artisan::call('direct-booking:maintain', ['--tenant' => $tenant->id]);

        $expired = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'expired');

        $recovered = $this->postJson($base."/orders/{$reference}/recover", [
            'expected_state_version' => $expired->json('data.state_version'),
        ], ['Authorization' => 'Bearer '.$recoveryToken, 'Idempotency-Key' => (string) Str::uuid()]);
        if ($recovered->status() !== 200) {
            $this->fail('Recover failed '.$recovered->status().': '.$recovered->getContent());
        }
        $recovered->assertOk()->assertJsonPath('data.state', 'started');

        $this->assertNotSame($sessionToken, $recovered->json('data.session_token'));
        $this->assertNotSame($recoveryToken, $recovered->json('data.recovery_token'));
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $recovered->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertDatabaseCount('payments', 0);
        $this->assertNotSame('confirmed', $recovered->json('data.state'));
    }

    public function test_expired_hold_cannot_checkout_and_releases_inventory(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$reference, $auth, $held] = $this->heldOrder($base, $stay);

        app(TenantContext::class)->set($tenant, $membership);
        $order = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $order->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        $order->reservation->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        Artisan::call('direct-booking:maintain', ['--tenant' => $tenant->id]);
        Artisan::call('reservation-holds:expire');

        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'hold_expired');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Tentative->value)->count());
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Confirmed->value)->count());

        $this->postJson($base.'/availability', $stay)
            ->assertOk()
            ->assertJsonPath('data.options.0.bookable', true);
        [$otherReference, $otherAuth, $otherQuoted] = $this->quotedOrder($base, $stay);
        $otherHold = $this->holdBody($otherQuoted);
        $otherHold['guest']['email'] = 'second-guest@example.test';
        $this->postJson($base."/orders/{$otherReference}/hold", $otherHold, $otherAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.state', 'held');
    }

    public function test_cop_hosted_capability_without_mco_site_is_not_launch_ready(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting] = $this->launchReadyCopProperty($property);
        $this->copHostedConnection($property->id, site: 'MLA');

        $report = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting);
        $this->assertFalse($report->ready);
        $this->assertContains('hosted_checkout_not_ready:COP', $report->blockingReasons);
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem, resource} */
    private function launchReadyCopProperty($property): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'cop-checkout-lodge',
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'COP',
            'supported_currencies' => ['COP'],
            'bot_verification_required' => false,
            'accessible_fallback_url' => 'https://book.example.test/contact',
        ]);
        $category = $this->category($property, 'room');
        $resource = Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'name' => 'Casa COP Internal',
            'code' => 'ROOM-COP-INT',
            'capacity' => 4,
        ]);
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id,
            'kind' => 'category',
            'resource_category_id' => $category->id,
            'is_enabled' => true,
        ]);
        $this->media($this->publication($property->id, DirectBookingPublicationKind::Property));
        $this->media($this->publication($property->id, DirectBookingPublicationKind::Category, $item->id));
        foreach ([
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ] as $kind) {
            $this->publication($property->id, $kind);
        }
        $instructions = $this->publication($property->id, DirectBookingPublicationKind::BankTransferInstructions);
        $capability = DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'method' => 'manual_bank_transfer',
            'is_enabled' => true,
            'instructions_publication_id' => $instructions->id,
        ]);
        DirectBookingPaymentInstruction::query()->create([
            'property_id' => $property->id,
            'direct_booking_payment_capability_id' => $capability->id,
            'publication_id' => $instructions->id,
            'locale' => 'en',
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id,
            'name' => 'Public COP checkout rate',
            'currency' => 'COP',
            'state' => 'draft',
            'is_active' => true,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 40_000,
            'adult_amount_minor' => 40_000,
            'child_amount_minor' => 20_000,
            'infant_amount_minor' => 0,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        return [$setting, $item, $resource];
    }

    private function copHostedConnection(string $propertyId, string $site = 'MCO'): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'property_id' => $propertyId,
            'name' => 'COP Checkout Pro',
            'type' => 'payment',
            'provider' => 'mercado_pago',
            'product' => 'checkout_pro',
            'external_account_id' => 'seller-mco',
            'environment' => 'sandbox',
            'status' => 'connected',
            'is_enabled' => true,
            'capabilities' => ['payment.hosted_checkout'],
            'configuration' => [
                'charge_currency' => 'COP',
                'site' => $site,
                'return_url_base' => 'https://book.example.test',
                'webhook_key' => str_repeat('c', 48),
                'webhook_secret_reference' => 'env:DIRECT_BOOKING_COP_MP_WEBHOOK',
                'transport' => 'deterministic_fixture',
                'fixture' => [
                    'preference_id' => 'pref-cop-hosted',
                ],
            ],
            'secret_reference' => 'env:DIRECT_BOOKING_COP_MP_TOKEN',
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.hosted_checkout',
            'direction' => 'outbound',
            'state' => 'enabled',
            'configuration_version' => 1,
        ]);
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyId,
            'currency' => 'COP',
            'method' => 'hosted_checkout',
            'is_enabled' => true,
            'provider_connection_id' => $connection->id,
        ]);

        return $connection->fresh();
    }

    private function storeApprovedPayment(IntegrationConnection $connection, PaymentAttempt $attempt): void
    {
        $this->storePaymentFixture($connection, [
            'id' => 'cop-approved-1',
            'collector_id' => 'seller-mco',
            'external_reference' => $attempt->external_reference,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => number_format($attempt->charge_amount_minor / 100, 2, '.', ''),
            'currency_id' => 'COP',
            'fee_details' => [],
            'transaction_details' => ['net_received_amount' => number_format($attempt->charge_amount_minor / 100, 2, '.', '')],
        ]);
    }

    private function storeRejectedPayment(IntegrationConnection $connection, PaymentAttempt $attempt): void
    {
        $this->storePaymentFixture($connection, [
            'id' => 'cop-rejected-1',
            'collector_id' => 'seller-mco',
            'external_reference' => $attempt->external_reference,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_other_reason',
            'transaction_amount' => number_format($attempt->charge_amount_minor / 100, 2, '.', ''),
            'currency_id' => 'COP',
            'fee_details' => [],
        ]);
    }

    /** @param array<string, mixed> $payment */
    private function storePaymentFixture(IntegrationConnection $connection, array $payment): void
    {
        $configuration = $connection->fresh()->configuration ?? [];
        $configuration['fixture'] = [
            'preference_id' => data_get($configuration, 'fixture.preference_id', 'pref-cop-hosted'),
            'payment' => $payment,
        ];
        $connection->forceFill(['configuration' => $configuration])->save();
    }

    private function providerEvent(IntegrationConnection $connection, string $resourceId, string $deliveryId): ProviderEvent
    {
        return ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => $connection->external_account_id,
            'delivery_id' => $deliveryId,
            'topic' => 'payment',
            'event_type' => 'payment',
            'action' => 'payment.updated',
            'resource_id' => $resourceId,
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', $deliveryId),
        ]);
    }

    /** @return array{arrival_date: string, departure_date: string, occupancy: array{adults: int, children: int, infants: int}, category_key: string, currency: string, locale: string} */
    private function stay(string $categoryKey): array
    {
        return [
            'arrival_date' => now()->addDays(20)->toDateString(),
            'departure_date' => now()->addDays(21)->toDateString(),
            'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'category_key' => $categoryKey,
            'currency' => 'COP',
            'locale' => 'en',
        ];
    }

    /** @param array<string, mixed> $stay @return array{0: string, 1: array{Authorization: string}, 2: TestResponse} */
    private function quotedOrder(string $base, array $stay): array
    {
        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reference = $begun->json('data.order_reference');
        $auth = ['Authorization' => 'Bearer '.$begun->json('data.session_token')];
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();

        return [$reference, $auth, $quoted];
    }

    /** @param array<string, mixed> $stay @return array{0: string, 1: array{Authorization: string}, 2: TestResponse} */
    private function heldOrder(string $base, array $stay): array
    {
        [$reference, $auth, $quoted] = $this->quotedOrder($base, $stay);
        $held = $this->postJson($base."/orders/{$reference}/hold", $this->holdBody($quoted), $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();

        return [$reference, $auth, $held];
    }

    /** @return array<string, mixed> */
    private function holdBody(TestResponse $quoted): array
    {
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => true],
        ])->all();

        return [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => 'public@example.test', 'phone' => '+573001112233'],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ];
    }

    private function publication(string $propertyId, DirectBookingPublicationKind $kind, ?string $itemId = null): DirectBookingPublication
    {
        return DirectBookingPublication::query()->create([
            'property_id' => $propertyId,
            'public_item_id' => $itemId,
            'kind' => $kind,
            'locale' => 'en',
            'version' => 1,
            'state' => DirectBookingPublicationState::Published,
            'title' => ucfirst(str_replace('_', ' ', $kind->value)),
            'summary' => 'Safe public summary.',
            'body' => 'Published content.',
            'effective_at' => now()->subMinute(),
            'published_at' => now(),
        ]);
    }

    private function media(DirectBookingPublication $publication): void
    {
        DirectBookingPublicMedia::query()->create([
            'publication_id' => $publication->id,
            'media_reference' => 'public-media://direct-booking/test.webp',
            'mime_type' => 'image/webp',
            'alt_text' => 'Accessible property image',
            'width' => 1200,
            'height' => 800,
        ]);
    }
}
