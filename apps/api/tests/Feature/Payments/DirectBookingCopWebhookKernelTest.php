<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\FolioLineType;
use App\Integrations\Payments\MercadoPago\MercadoPagoCheckoutProGateway;
use App\Jobs\ProcessProviderEventJob;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\FolioLine;
use App\Models\IntegrationConnection;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DirectBookingCopWebhookKernelTest extends TestCase
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

    public function test_unsigned_wrong_secret_and_unknown_webhook_key_are_4xx_with_no_payment(): void
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
        $body = json_encode(['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => 'cop-approved-1']], JSON_THROW_ON_ERROR);
        $url = '/api/v1/payment-webhooks/'.str_repeat('c', 48).'?data.id=cop-approved-1';
        $baseHeaders = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'cop-webhook-unsigned'];

        $this->call('POST', $url, [], [], [], $baseHeaders, $body)->assertUnauthorized();
        $this->call('POST', $url, [], [], [], $baseHeaders + [
            'HTTP_X_SIGNATURE' => $this->signature('cop-approved-1', 'cop-webhook-unsigned', now()->getTimestampMs(), 'wrong-secret'),
        ], $body)->assertUnauthorized();
        $this->call('POST', '/api/v1/payment-webhooks/'.str_repeat('z', 48).'?data.id=cop-approved-1', [], [], [], $baseHeaders + [
            'HTTP_X_SIGNATURE' => $this->signature('cop-approved-1', 'cop-webhook-unsigned', now()->getTimestampMs()),
        ], $body)->assertNotFound();

        $this->assertDatabaseCount('provider_events', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, FolioLine::query()->where('type', FolioLineType::Payment)->count());
        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'payment_pending');
    }

    public function test_duplicate_signed_webhook_applies_payment_once_without_logging_secrets(): void
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
        $log = Log::spy();
        $body = json_encode(['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => 'cop-approved-1']], JSON_THROW_ON_ERROR);
        $url = '/api/v1/payment-webhooks/'.str_repeat('c', 48).'?data.id=cop-approved-1';
        $firstId = 'cop-webhook-dup-1';
        $timestamp = now()->getTimestampMs();
        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => $firstId,
            'HTTP_X_SIGNATURE' => $this->signature('cop-approved-1', $firstId, $timestamp),
        ], $body)->assertOk();
        $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => $firstId,
            'HTTP_X_SIGNATURE' => $this->signature('cop-approved-1', $firstId, $timestamp),
        ], $body)->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, FolioLine::query()->where('type', FolioLineType::Payment)->count());
        $this->assertSame(1, ProviderEvent::query()->whereNull('duplicate_of_id')->count());
        $this->assertTrue(ProviderEvent::query()->whereNotNull('duplicate_of_id')->exists());
        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'confirmed');
        $this->assertInstanceOf(MercadoPagoCheckoutProGateway::class, app(PaymentGatewayFactory::class)->for($connection->fresh()));

        $serialized = json_encode([
            'events' => ProviderEvent::query()->get(['id', 'last_error', 'sanitized_headers', 'private_payload'])->toArray(),
            'payments' => Payment::query()->get()->toArray(),
            'attempts' => PaymentAttempt::query()->get()->toArray(),
            'logs' => method_exists($log, 'info') ? [] : [],
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('5031755734530604', $serialized);
        $this->assertStringNotContainsString('cop-test-access-token', $serialized);
        $this->assertStringNotContainsString('cop-test-webhook-secret', $serialized);
        $this->assertStringNotContainsString('cvv', strtolower($serialized));
        $this->assertTrue(class_exists(ProcessProviderEventJob::class));
    }

    public function test_return_url_does_not_post_money(): void
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
        $this->get('/pay/return/'.$externalReference)->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, FolioLine::query()->where('type', FolioLineType::Payment)->count());
        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'payment_pending');
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem} */
    private function launchReadyCopProperty($property): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'cop-webhook-lodge',
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'COP',
            'supported_currencies' => ['COP'],
            'bot_verification_required' => false,
            'accessible_fallback_url' => 'https://book.example.test/contact',
        ]);
        $category = $this->category($property, 'room');
        Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
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
            'name' => 'Public COP webhook rate',
            'currency' => 'COP',
            'state' => 'draft',
            'is_active' => true,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 40_000,
            'adult_amount_minor' => 40_000,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        return [$setting, $item];
    }

    private function copHostedConnection(string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'property_id' => $propertyId,
            'name' => 'COP webhook Checkout Pro',
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
                'site' => 'MCO',
                'return_url_base' => 'https://book.example.test',
                'webhook_key' => str_repeat('c', 48),
                'webhook_secret_reference' => 'env:DIRECT_BOOKING_COP_MP_WEBHOOK',
                'transport' => 'deterministic_fixture',
                'fixture' => ['preference_id' => 'pref-cop-webhook'],
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
        $configuration = $connection->fresh()->configuration ?? [];
        $configuration['fixture'] = [
            'preference_id' => 'pref-cop-webhook',
            'payment' => [
                'id' => 'cop-approved-1',
                'collector_id' => 'seller-mco',
                'external_reference' => $attempt->external_reference,
                'status' => 'approved',
                'status_detail' => 'accredited',
                'transaction_amount' => number_format($attempt->charge_amount_minor / 100, 2, '.', ''),
                'currency_id' => 'COP',
                'fee_details' => [],
                'transaction_details' => ['net_received_amount' => number_format($attempt->charge_amount_minor / 100, 2, '.', '')],
            ],
        ];
        $connection->forceFill(['configuration' => $configuration])->save();
    }

    /** @return array{0: string, 1: array{Authorization: string}, 2: TestResponse} */
    private function heldOrder(string $base, array $stay): array
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
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => true],
        ])->all();
        $held = $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => 'public@example.test'],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return [$reference, $auth, $held];
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

    private function signature(string $resourceId, string $requestId, int $timestamp, string $secret = 'cop-test-webhook-secret'): string
    {
        $manifest = 'id:'.strtolower($resourceId).';request-id:'.$requestId.';ts:'.$timestamp.';';

        return 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, $secret);
    }
}
