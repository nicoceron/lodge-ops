<?php

namespace Tests\Feature\DirectBooking;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderPayment;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\ProviderEventState;
use App\Models\CatalogItem;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\GuestPaymentEvidence;
use App\Models\IntegrationConnection;
use App\Models\Program;
use App\Models\ProviderEvent;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Services\DirectBooking\DirectBookingLaunchReadinessEvaluator;
use App\Services\Payments\ProcessProviderEvent;
use App\Services\ReviewPaymentEvidence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class DirectBookingApiTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'front_desk_tenders.evidence_scanner_available' => true,
        ]);
    }

    public function test_anonymous_manual_payment_journey_confirms_once_with_exact_api_replay(): void
    {
        [$tenant, $property, $actor, $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyProperty($property);
        $program = Program::query()->create([
            'property_id' => $property->id,
            'name' => 'Public riding program',
            'currency' => 'USD',
            'price_minor' => 5_000,
            'requires_accommodation' => true,
            'is_active' => true,
        ]);
        $horseCategory = $this->category($property, 'horse');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $horseCategory->id, 'capacity' => 2]);
        $program->requirements()->create([
            'resource_category_id' => $horseCategory->id,
            'minimum_quantity' => 1,
        ]);
        $programItem = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id,
            'kind' => 'program',
            'program_id' => $program->id,
            'is_enabled' => true,
        ]);
        $programPublication = $this->publication($property->id, DirectBookingPublicationKind::Program, $programItem->id);
        $this->media($programPublication);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson("/api/v1/properties/{$property->id}/direct-booking-readiness")
            ->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.blocking_reasons', []);

        $this->getJson($base.'?locale=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonMissingPath('data.bookables.0.resource_category_id');

        $availability = [
            'arrival_date' => now()->addDays(20)->toDateString(),
            'departure_date' => now()->addDays(22)->toDateString(),
            'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'category_key' => $categoryItem->public_key,
            'program_key' => $programItem->public_key,
            'currency' => 'USD',
            'locale' => 'en',
        ];
        $this->postJson($base.'/availability', $availability)
            ->assertOk()->assertJsonFragment(['key' => $programItem->public_key, 'kind' => 'program', 'bookable' => true])
            ->assertJsonMissingPath('data.options.0.available_units');

        $beginKey = (string) Str::uuid();
        $beginBody = [
            'locale' => 'en', 'currency' => 'USD',
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_begin',
            'attribution' => ['utm_source' => 'test'],
        ];
        $begun = $this->postJson($base.'/orders', $beginBody, ['Idempotency-Key' => $beginKey])->assertCreated();
        $replay = $this->postJson($base.'/orders', $beginBody, ['Idempotency-Key' => $beginKey])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($begun->getContent(), $replay->getContent());
        $this->postJson($base.'/orders', [...$beginBody, 'locale' => 'es'], ['Idempotency-Key' => $beginKey])
            ->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $reference = $begun->json('data.order_reference');
        $token = $begun->json('data.session_token');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->postJson($base."/orders/{$reference}/quote", $availability + [
            'expected_state_version' => 1,
            'optional_service_keys' => [(string) Str::ulid()],
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();

        $quoted = $this->postJson($base."/orders/{$reference}/quote", $availability + [
            'expected_state_version' => 1,
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonFragment(['type' => 'service', 'description' => 'Public riding program'])
            ->assertJsonFragment(['type' => 'included_service', 'description' => 'Breakfast · included']);
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => $policy['kind'] !== 'marketing_consent'],
        ])->all();

        $rejectedConsents = $consents;
        $rejectedConsents['terms']['accepted'] = false;
        $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'email' => 'public@example.test'],
            'consents' => $rejectedConsents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('deposits', 0);

        $held = $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => 'public@example.test', 'phone' => '+12025550123'],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.state', 'held');

        $checkout = $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'manual_bank_transfer',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.state', 'awaiting_manual_payment');

        $evidence = $this->post($base."/orders/{$reference}/manual-payment-evidence", [
            'expected_state_version' => $checkout->json('data.state_version'),
            'evidence' => UploadedFile::fake()->image('receipt.png', 20, 20),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.state', 'evidence_pending');

        app(TenantContext::class)->set($tenant, $membership);
        $paymentEvidence = GuestPaymentEvidence::query()->sole();
        $deposit = $paymentEvidence->reservation->deposits()->where('schedule_type', 'deposit_50')->firstOrFail();
        app(ReviewPaymentEvidence::class)->approve($paymentEvidence, $deposit->id, $actor->id, 'Verified test transfer');

        $status = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()->assertJsonPath('data.state', 'confirmed');
        $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertOk()->assertJsonPath('data.state', 'confirmed');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 5);
        $this->assertDatabaseCount('allocations', 2);
        $this->assertDatabaseCount('direct_booking_command_responses', 5);
        $this->assertSame('view_confirmation', $status->json('data.actions.0'));
        $this->assertDatabaseMissing('direct_booking_command_responses', ['response_body_encrypted' => $begun->getContent()]);
        DB::table('direct_booking_command_responses')->update(['expires_at' => now()->subMinute()]);
        Artisan::call('direct-booking:maintain', ['--tenant' => $tenant->id, '--cleanup' => true]);
        $this->assertDatabaseCount('direct_booking_command_responses', 0);
    }

    public function test_authoritative_provider_lookup_is_the_only_hosted_confirmation_authority(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyProperty($property);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $connection = IntegrationConnection::query()->create([
            'property_id' => $property->id,
            'name' => 'Direct booking provider',
            'type' => 'payment',
            'provider' => 'mercado_pago',
            'product' => 'checkout_pro',
            'external_account_id' => 'seller-direct',
            'environment' => 'sandbox',
            'status' => 'connected',
            'is_enabled' => true,
            'capabilities' => ['payment.hosted_checkout'],
            'configuration' => [
                'provider_account' => 'seller-direct',
                'charge_currency' => 'USD',
                'return_url_base' => 'https://book.example.test',
                'webhook_key' => str_repeat('d', 48),
            ],
            'secret_reference' => 'env:DIRECT_BOOKING_TEST_TOKEN',
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.hosted_checkout', 'direction' => 'outbound', 'state' => 'enabled', 'configuration_version' => 1,
        ]);
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'method' => 'hosted_checkout',
            'is_enabled' => true,
            'provider_connection_id' => $connection->id,
        ]);
        $this->assertSame([], app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting)->blockingReasons);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = [
            'arrival_date' => now()->addDays(30)->toDateString(),
            'departure_date' => now()->addDays(32)->toDateString(),
            'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'category_key' => $categoryItem->public_key,
            'currency' => 'USD',
            'locale' => 'en',
        ];
        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en', 'currency' => 'USD', 'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $reference = $begun->json('data.order_reference');
        $auth = ['Authorization' => 'Bearer '.$begun->json('data.session_token')];
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => true],
        ])->all();
        $held = $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Hosted', 'email' => 'hosted@example.test'],
            'consents' => $consents,
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $checkout = $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.state', 'payment_pending');

        // A browser return/status poll is intentionally non-authoritative.
        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'payment_pending');
        app(TenantContext::class)->set($tenant, $membership);
        $attempt = $connection->paymentAttempts()->sole();
        $fake->payments['direct-rejected-1'] = new ProviderPayment(
            'direct-rejected-1', $attempt->external_reference, 'rejected', 'cc_rejected_other_reason',
            $attempt->charge_amount_minor, $attempt->charge_currency, 'seller-direct',
        );
        $rejectedEvent = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => 'seller-direct',
            'delivery_id' => 'direct-delivery-rejected-1',
            'topic' => 'payment',
            'event_type' => 'payment',
            'action' => 'payment.updated',
            'resource_id' => 'direct-rejected-1',
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', 'direct-delivery-rejected-1'),
        ]);
        app(ProcessProviderEvent::class)->handle($rejectedEvent);
        $failed = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertJsonPath('data.state', 'payment_failed');
        $retry = $this->postJson($base."/orders/{$reference}/payments/retry", [
            'expected_state_version' => $failed->json('data.state_version'),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.state', 'payment_pending');
        app(TenantContext::class)->set($tenant, $membership);
        $currentRequestId = DirectBookingOrder::query()->where('public_reference', $reference)->value('payment_request_id');
        $attempt = $connection->paymentAttempts()->where('payment_request_id', $currentRequestId)->sole();
        $this->assertDatabaseHas('payment_requests', ['state' => 'superseded']);
        $fake->payments['direct-approved-1'] = new ProviderPayment(
            'direct-approved-1', $attempt->external_reference, 'approved', 'accredited',
            $attempt->charge_amount_minor, $attempt->charge_currency, 'seller-direct',
        );
        $event = ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => 'seller-direct',
            'delivery_id' => 'direct-delivery-1',
            'topic' => 'payment',
            'event_type' => 'payment',
            'action' => 'payment.updated',
            'resource_id' => 'direct-approved-1',
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', 'direct-delivery-1'),
        ]);
        app(ProcessProviderEvent::class)->handle($event);
        app(ProcessProviderEvent::class)->handle($event->fresh());

        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'confirmed');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_requests', 2);
        $this->assertDatabaseCount('direct_booking_orders', 1);
        $this->assertDatabaseCount('operational_tasks', 0);
        $this->assertSame('payment_pending', $checkout->json('data.state'));
        $this->assertSame('payment_pending', $retry->json('data.state'));
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem} */
    private function launchReadyProperty($property): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'api-lodge',
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'bot_verification_required' => false,
            'accessible_fallback_url' => 'https://book.example.test/contact',
        ]);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 4]);
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id,
            'kind' => 'category',
            'resource_category_id' => $category->id,
            'is_enabled' => true,
        ]);
        $propertyPublication = $this->publication($property->id, DirectBookingPublicationKind::Property);
        $this->media($propertyPublication);
        $categoryPublication = $this->publication($property->id, DirectBookingPublicationKind::Category, $item->id);
        $this->media($categoryPublication);
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
            'currency' => 'USD',
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
            'name' => 'Public rate',
            'currency' => 'USD',
            'state' => 'draft',
            'is_active' => true,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 20_000,
        ]);
        $breakfast = CatalogItem::query()->create([
            'sku' => 'PUBLIC-BREAKFAST',
            'name' => 'Breakfast',
            'type' => 'service',
            'currency' => 'USD',
            'price_minor' => 1_500,
        ]);
        $plan->services()->create([
            'catalog_item_id' => $breakfast->id,
            'selection_type' => 'included',
            'quantity_basis' => 'per_stay',
            'default_quantity' => 1,
            'maximum_quantity' => 1,
            'is_active' => true,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        return [$setting, $item];
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
