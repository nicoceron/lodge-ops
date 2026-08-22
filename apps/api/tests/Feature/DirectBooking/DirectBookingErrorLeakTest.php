<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Models\CatalogItem;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DirectBookingErrorLeakTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'app.debug' => true,
        ]);
    }

    /**
     * A framework-level 404 for an invalid-format direct-booking order ref
     * (not matching the ULID route pattern) must return the contract JSON
     * error envelope — not the Symfony debug page with traces and /app/ paths.
     *
     * This must hold even when APP_DEBUG=true.
     */
    public function test_invalid_format_ref_returns_clean_json_404_with_app_debug_true(): void
    {
        $this->assertNotEmpty(config('app.debug'), 'Test precondition: APP_DEBUG must be true.');

        $base = '/api/v1/direct-booking/properties/api-lodge';

        // POST quote with an invalid-format ref (not a 26-char ULID).
        $response = $this->postJson("{$base}/orders/not-a-valid-ref/quote", [
            'arrival_date' => now()->addDays(20)->toDateString(),
            'departure_date' => now()->addDays(22)->toDateString(),
            'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'currency' => 'USD',
            'locale' => 'en',
            'expected_state_version' => 1,
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $this->assertCleanContractError($response, 404, DirectBookingErrorCode::NotFound->value);

        // GET status with an invalid-format ref.
        $statusResponse = $this->getJson("{$base}/orders/ALSO-NOT-VALID/status");
        $this->assertCleanContractError($statusResponse, 404, DirectBookingErrorCode::NotFound->value);

        // POST hold with an invalid-format ref.
        $holdResponse = $this->postJson("{$base}/orders/still-bad/hold", [
            'expected_state_version' => 1,
            'guest' => ['first_name' => 'A', 'email' => 'a@b.test'],
            'consents' => ['terms' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true]],
            'turnstile_token' => 'x',
            'turnstile_action' => 'direct_booking_hold',
        ], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertCleanContractError($holdResponse, 404, DirectBookingErrorCode::NotFound->value);
    }

    /**
     * Existing clean contract envelopes (not_found, unavailable, quote_stale)
     * must not regress — no trace, SQL, or /app/ paths.
     */
    public function test_existing_clean_envelopes_are_unchanged_with_app_debug_true(): void
    {
        $this->assertNotEmpty(config('app.debug'), 'Test precondition: APP_DEBUG must be true.');

        [$tenant, $property] = $this->tenantEnvironment(authenticate: false);
        [$setting, $categoryItem] = $this->launchReadyProperty($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;

        // Begin a real order to get a session token.
        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'USD',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $ref = $begun->json('data.order_reference');
        $token = $begun->json('data.session_token');

        // not_found: valid-format ref but wrong/missing token → controller-level not_found.
        $notFound = $this->getJson("{$base}/orders/{$ref}");
        $this->assertCleanContractError($notFound, 404, DirectBookingErrorCode::NotFound->value);

        // not_found: valid-format ref that doesn't exist with no token.
        $fakeRef = (string) Str::ulid();
        $notFound2 = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("{$base}/orders/{$fakeRef}");
        $this->assertCleanContractError($notFound2, 404, DirectBookingErrorCode::NotFound->value);

        // quote_stale: hold with wrong expected_state_version.
        $quoteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/orders/{$ref}/quote", [
                'arrival_date' => now()->addDays(20)->toDateString(),
                'departure_date' => now()->addDays(22)->toDateString(),
                'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
                'category_key' => $categoryItem->public_key,
                'currency' => 'USD',
                'locale' => 'en',
                'expected_state_version' => 1,
            ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $staleVersion = $quoteResponse->json('data.state_version') + 100;
        $staleHold = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("{$base}/orders/{$ref}/hold", [
                'expected_state_version' => $staleVersion,
                'guest' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane-'.Str::uuid().'@example.test',
                    'phone' => '+15555550100',
                ],
                'consents' => [
                    'terms' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
                    'privacy' => ['version' => 1, 'checksum' => str_repeat('b', 64), 'accepted' => true],
                    'cancellation' => ['version' => 1, 'checksum' => str_repeat('c', 64), 'accepted' => true],
                    'no_show' => ['version' => 1, 'checksum' => str_repeat('d', 64), 'accepted' => true],
                ],
                'turnstile_token' => 'test-token',
                'turnstile_action' => 'direct_booking_hold',
            ], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertCleanContractError($staleHold, 409, DirectBookingErrorCode::QuoteStale->value);
    }

    /**
     * Assert the response is a clean contract error envelope with no leaked
     * debug information (trace, file, line, SQL, /app/ paths).
     */
    private function assertCleanContractError(
        TestResponse $response,
        int $expectedStatus,
        string $expectedCode,
    ): void {
        $response->assertStatus($expectedStatus);
        $response->assertHeader('Content-Type', 'application/json');

        $body = $response->getContent();
        $this->assertNotEmpty($body, 'Error response body must not be empty.');

        $json = json_decode($body, true);
        $this->assertIsArray($json, 'Error response must be valid JSON.');
        $this->assertArrayHasKey('error', $json, 'Error response must have an "error" envelope.');
        $this->assertSame($expectedCode, $json['error']['code'] ?? null, 'Error code must match.');
        $this->assertArrayHasKey('message', $json['error'], 'Error envelope must include message.');
        $this->assertArrayHasKey('correlation_id', $json['error'], 'Error envelope must include correlation_id.');
        $this->assertArrayHasKey('retryable', $json['error'], 'Error envelope must include retryable.');

        // No leaked debug information.
        $this->assertStringNotContainsString('trace', $body, 'Error response must not contain a stack trace.');
        $this->assertStringNotContainsString('/app/', $body, 'Error response must not contain /app/ file paths.');
        $this->assertStringNotContainsString('"file"', $body, 'Error response must not contain file paths.');
        $this->assertStringNotContainsString('"line"', $body, 'Error response must not contain line numbers.');
        $this->assertStringNotContainsString('exception', $body, 'Error response must not contain exception class names.');
        $this->assertStringNotContainsString('SELECT', $body, 'Error response must not contain SQL.');
        $this->assertStringNotContainsString('INSERT', $body, 'Error response must not contain SQL.');
        $this->assertStringNotContainsString('SQL', $body, 'Error response must not contain SQL.');
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem} */
    private function launchReadyProperty(string $propertyId): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $propertyId,
            'public_slug' => 'api-lodge',
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'bot_verification_required' => false,
            'accessible_fallback_url' => 'https://book.example.test/contact',
        ]);
        $category = $this->category($propertyId, 'room');
        Resource::factory()->create(['property_id' => $propertyId, 'category_id' => $category->id, 'capacity' => 4]);
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $propertyId,
            'kind' => 'category',
            'resource_category_id' => $category->id,
            'is_enabled' => true,
        ]);
        $propertyPublication = $this->publication($propertyId, DirectBookingPublicationKind::Property);
        $this->media($propertyPublication);
        $categoryPublication = $this->publication($propertyId, DirectBookingPublicationKind::Category, $item->id);
        $this->media($categoryPublication);
        foreach ([
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ] as $kind) {
            $this->publication($propertyId, $kind);
        }
        $instructions = $this->publication($propertyId, DirectBookingPublicationKind::BankTransferInstructions);
        $capability = DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyId,
            'currency' => 'USD',
            'method' => 'manual_bank_transfer',
            'is_enabled' => true,
            'instructions_publication_id' => $instructions->id,
        ]);
        DirectBookingPaymentInstruction::query()->create([
            'property_id' => $propertyId,
            'direct_booking_payment_capability_id' => $capability->id,
            'publication_id' => $instructions->id,
            'locale' => 'en',
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $propertyId,
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
