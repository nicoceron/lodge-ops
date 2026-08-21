<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use App\Enums\ReservationStatus;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\PaymentRequest;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DirectBookingSearchQuoteHoldTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    }

    public function test_launch_ready_property_projection_is_public_and_includes_cop(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting, $categoryItem, $resource] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;

        $response = $this->getJson($base.'?locale=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.slug', $setting->public_slug)
            ->assertJsonPath('data.bookables.0.key', $categoryItem->public_key)
            ->assertJsonPath('data.bookables.0.kind', 'category')
            ->assertJsonPath('data.supported_currencies.0', 'COP')
            ->assertJsonPath('data.payment_capabilities.0.currency', 'COP')
            ->assertJsonMissingPath('data.bookables.0.resource_category_id')
            ->assertJsonMissingPath('data.bookables.0.resource_id');

        $body = $response->getContent();
        $this->assertStringNotContainsString($resource->id, $body);
        $this->assertStringNotContainsString($resource->name, $body);
        $this->assertStringNotContainsString($resource->code, $body);
        $this->assertStringNotContainsString('staff_note', $body);
        $this->assertStringNotContainsString('secret_reference', $body);
        $this->assertStringNotContainsString('env:DIRECT_BOOKING_TEST_TOKEN', $body);
    }

    public function test_availability_returns_public_category_and_program_keys_only(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting, $categoryItem, $resource] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);

        $response = $this->postJson($base.'/availability', $stay)
            ->assertOk()
            ->assertJsonPath('data.options.0.key', $categoryItem->public_key)
            ->assertJsonPath('data.options.0.kind', 'category')
            ->assertJsonPath('data.options.0.bookable', true)
            ->assertJsonMissingPath('data.options.0.available_units')
            ->assertJsonMissingPath('data.options.0.resource_id')
            ->assertJsonMissingPath('data.options.0.id');

        $body = $response->getContent();
        $this->assertStringNotContainsString($resource->id, $body);
        $this->assertStringNotContainsString($resource->name, $body);
        $this->assertStringNotContainsString($resource->code, $body);
        $this->assertDoesNotMatchRegularExpression('/"kind"\s*:\s*"(?!category|program)/', $body);
    }

    public function test_unavailable_dates_cannot_be_quoted_or_held(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem, $resource] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        ResourceBlock::query()->create([
            'resource_id' => $resource->id,
            'starts_at' => $stay['arrival_date'].' 00:00:00 '.$property->timezone,
            'ends_at' => $stay['departure_date'].' 00:00:00 '.$property->timezone,
            'reason' => 'sold_out',
        ]);

        $this->postJson($base.'/availability', $stay)
            ->assertOk()
            ->assertJsonPath('data.options.0.bookable', false);

        [$reference, $auth] = $this->beginOrder($base);
        $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'unavailable');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('payment_requests', 0);
        $this->assertDatabaseCount('allocations', 0);
    }

    public function test_begin_order_issues_opaque_session_and_ref_alone_cannot_read(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;

        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $reference = $begun->json('data.order_reference');
        $token = $begun->json('data.session_token');
        $this->assertNotSame($reference, $token);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $reference);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $this->assertSame('started', $begun->json('data.state'));

        $this->getJson($base.'/orders/'.$reference)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->getJson($base.'/orders/'.$reference, ['Authorization' => 'Bearer '.$reference])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->getJson($base.'/orders/'.$reference, ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.order_reference', $reference)
            ->assertJsonPath('data.state', 'started');
    }

    public function test_authoritative_quote_returns_cop_integer_minor_units_and_rejects_client_totals(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$reference, $auth] = $this->beginOrder($base);

        $this->postJson($base."/orders/{$reference}/quote", $stay + [
            'expected_state_version' => 1,
            'total' => ['amount_minor' => 1, 'currency' => 'USD'],
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()
            ->assertJsonPath('data.state', 'quoted')
            ->assertJsonPath('data.total.currency', 'COP')
            ->assertJsonPath('data.deposit.currency', 'COP');

        $total = $quoted->json('data.total.amount_minor');
        $deposit = $quoted->json('data.deposit.amount_minor');
        $this->assertIsInt($total);
        $this->assertIsInt($deposit);
        $this->assertGreaterThan(0, $total);
        $this->assertGreaterThan(0, $deposit);
        $this->assertLessThanOrEqual($total, $deposit);
        $this->assertNotNull($quoted->json('data.quote_expires_at'));
        $this->assertNotEmpty($quoted->json('data.lines'));
        $this->assertSame(80_000, $total);
    }

    public function test_stale_checksum_or_version_hold_returns_quote_stale_with_no_hold(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);

        [$staleVersionRef, $staleVersionAuth, $staleVersionQuote] = $this->quotedOrder($base, $stay);
        $this->postJson($base."/orders/{$staleVersionRef}/hold", $this->holdBody($staleVersionQuote, 1), $staleVersionAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'quote_stale');
        $this->assertHoldNotCreated($tenant, $membership);

        [$checksumRef, $checksumAuth, $checksumQuote] = $this->quotedOrder($base, $stay);
        app(TenantContext::class)->set($tenant, $membership);
        $checksumOrder = DirectBookingOrder::query()->where('public_reference', $checksumRef)->firstOrFail();
        DB::table('booking_quotes')->where('id', $checksumOrder->booking_quote_id)->update([
            'checksum' => str_repeat('ab', 32),
        ]);
        $this->postJson($base."/orders/{$checksumRef}/hold", $this->holdBody($checksumQuote), $checksumAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'quote_stale');
        $this->assertHoldNotCreated($tenant, $membership);

        [$expiredRef, $expiredAuth, $expiredQuote] = $this->quotedOrder($base, $stay);
        app(TenantContext::class)->set($tenant, $membership);
        $expiredOrder = DirectBookingOrder::query()->where('public_reference', $expiredRef)->firstOrFail();
        DB::table('booking_quotes')->where('id', $expiredOrder->booking_quote_id)->update([
            'expires_at' => now()->subMinute(),
        ]);
        $this->postJson($base."/orders/{$expiredRef}/hold", $this->holdBody($expiredQuote), $expiredAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'quote_stale');
        $this->assertHoldNotCreated($tenant, $membership);
    }

    public function test_hold_provisions_deposit_payment_request_while_reservation_is_held(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$reference, $auth, $quoted] = $this->quotedOrder($base, $stay);

        $held = $this->postJson($base."/orders/{$reference}/hold", $this->holdBody($quoted), $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk()
            ->assertJsonPath('data.state', 'held')
            ->assertJsonPath('data.order_reference', $reference);

        $this->assertNotNull($held->json('data.hold_expires_at'));
        app(TenantContext::class)->set($tenant, $membership);
        $order = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $reservation = $order->reservation()->firstOrFail();
        $this->assertSame(ReservationStatus::Hold, $reservation->status);
        $this->assertNull($reservation->confirmed_at);
        $this->assertNotNull($order->payment_request_id);
        $this->assertSame(1, $reservation->deposits()->count());
        $request = PaymentRequest::query()->findOrFail($order->payment_request_id);
        $this->assertSame($reservation->id, $request->reservation_id);
        $this->assertSame(PaymentRequestPurpose::Deposit, $request->purpose);
        $this->assertSame(PaymentRequestState::Open, $request->state);
        $this->assertSame('COP', $request->source_currency);
        $this->assertSame('COP', $request->charge_currency);
        $this->assertTrue($order->hold_expires_at->equalTo($reservation->hold_expires_at));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('payment_requests', 1);
        $this->assertSame(ReservationStatus::Hold, Reservation::query()->sole()->status);
    }

    public function test_hold_without_consents_or_with_different_selection_allocates_nothing(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$reference, $auth, $quoted] = $this->quotedOrder($base, $stay);
        $holdBody = $this->holdBody($quoted);

        $withoutConsents = $holdBody;
        unset($withoutConsents['consents']);
        $this->postJson($base."/orders/{$reference}/hold", $withoutConsents, $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
        $this->assertHoldNotCreated($tenant, $membership);

        $rejected = $holdBody;
        $rejected['consents']['terms']['accepted'] = false;
        $this->postJson($base."/orders/{$reference}/hold", $rejected, $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
        $this->assertHoldNotCreated($tenant, $membership);

        $wrongSelection = $holdBody;
        $wrongSelection['category_key'] = $categoryItem->public_key;
        $wrongSelection['arrival_date'] = now()->addDays(40)->toDateString();
        $wrongSelection['departure_date'] = now()->addDays(42)->toDateString();
        $wrongSelection['occupancy'] = ['adults' => 3, 'children' => 0, 'infants' => 0];
        $this->postJson($base."/orders/{$reference}/hold", $wrongSelection, $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
        $this->assertHoldNotCreated($tenant, $membership);

        $wrongCompanions = $holdBody;
        $wrongCompanions['companions'] = [
            ['first_name' => 'Extra', 'last_name' => 'Adult', 'guest_type' => 'adult'],
            ['first_name' => 'Other', 'last_name' => 'Adult', 'guest_type' => 'adult'],
        ];
        $this->postJson($base."/orders/{$reference}/hold", $wrongCompanions, $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
        $this->assertHoldNotCreated($tenant, $membership);
    }

    public function test_disabled_or_not_launch_ready_slug_cannot_quote_or_hold(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$reference, $auth, $quoted] = $this->quotedOrder($base, $stay);

        app(TenantContext::class)->set($tenant, $membership);
        $setting->forceFill(['direct_booking_enabled' => false])->save();

        $this->getJson($base.'?locale=en')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'booking_unavailable');
        $this->postJson($base.'/availability', $stay)
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'booking_unavailable');
        $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'booking_unavailable');
        $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertServiceUnavailable()->assertJsonPath('error.code', 'booking_unavailable');
        $this->postJson($base."/orders/{$reference}/hold", $this->holdBody($quoted), $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertServiceUnavailable()->assertJsonPath('error.code', 'booking_unavailable');
        $this->assertHoldNotCreated($tenant, $membership);

        $this->getJson('/api/v1/direct-booking/properties/not-a-ready-lodge?locale=en')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'booking_unavailable');
    }

    public function test_changing_occupancy_requires_a_new_quote(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $two = $this->stay($categoryItem->public_key, adults: 2);
        $three = $this->stay($categoryItem->public_key, adults: 3);
        [$reference, $auth] = $this->beginOrder($base);

        $quotedTwo = $this->postJson($base."/orders/{$reference}/quote", $two + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame(80_000, $quotedTwo->json('data.total.amount_minor'));

        $this->postJson($base."/orders/{$reference}/quote", $three + ['expected_state_version' => 2], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict();

        [$otherReference, $otherAuth] = $this->beginOrder($base);
        $quotedThree = $this->postJson($base."/orders/{$otherReference}/quote", $three + ['expected_state_version' => 1], $otherAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame(120_000, $quotedThree->json('data.total.amount_minor'));
        $this->assertNotSame($quotedTwo->json('data.total.amount_minor'), $quotedThree->json('data.total.amount_minor'));

        $wrongOccupancyHold = $this->holdBody($quotedTwo);
        $wrongOccupancyHold['companions'] = [
            ['first_name' => 'Extra', 'last_name' => 'Adult', 'guest_type' => 'adult'],
            ['first_name' => 'Third', 'last_name' => 'Adult', 'guest_type' => 'adult'],
        ];
        $this->postJson($base."/orders/{$reference}/hold", $wrongOccupancyHold, $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation_error');
        $this->assertHoldNotCreated($tenant, $membership);
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem, resource} */
    private function launchReadyCopProperty($property): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'cop-lodge',
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
            'name' => 'Casa 7 Internal',
            'code' => 'ROOM-7-INT',
            'capacity' => 4,
        ]);
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id,
            'kind' => 'category',
            'resource_category_id' => $category->id,
            'is_enabled' => true,
        ]);
        $propertyPublication = $this->publication($property->id, DirectBookingPublicationKind::Property);
        $this->media($propertyPublication);
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
            'name' => 'Public COP rate',
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

    /** @return array{arrival_date: string, departure_date: string, occupancy: array{adults: int, children: int, infants: int}, category_key: string, currency: string, locale: string} */
    private function stay(string $categoryKey, int $adults = 2): array
    {
        return [
            'arrival_date' => now()->addDays(20)->toDateString(),
            'departure_date' => now()->addDays(21)->toDateString(),
            'occupancy' => ['adults' => $adults, 'children' => 0, 'infants' => 0],
            'category_key' => $categoryKey,
            'currency' => 'COP',
            'locale' => 'en',
        ];
    }

    /** @return array{0: string, 1: array{Authorization: string}} */
    private function beginOrder(string $base): array
    {
        $begun = $this->postJson($base.'/orders', [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        return [$begun->json('data.order_reference'), ['Authorization' => 'Bearer '.$begun->json('data.session_token')]];
    }

    /** @param array<string, mixed> $stay @return array{0: string, 1: array{Authorization: string}, 2: \Illuminate\Testing\TestResponse} */
    private function quotedOrder(string $base, array $stay): array
    {
        [$reference, $auth] = $this->beginOrder($base);
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();

        return [$reference, $auth, $quoted];
    }

    /** @return array<string, mixed> */
    private function holdBody(TestResponse $quoted, int $expectedStateVersion = 2): array
    {
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => true],
        ])->all();

        return [
            'expected_state_version' => $expectedStateVersion,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => 'public@example.test', 'phone' => '+573001112233'],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ];
    }

    private function assertHoldNotCreated($tenant, $membership): void
    {
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('deposits', 0);
        $this->assertDatabaseCount('payment_requests', 0);
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
