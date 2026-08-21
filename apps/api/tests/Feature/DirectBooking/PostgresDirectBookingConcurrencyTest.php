<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\AllocationStatus;
use App\Enums\BookingQuoteStatus;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\ProviderEventState;
use App\Enums\ReservationStatus;
use App\Models\BookingQuote;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\PaymentAttempt;
use App\Models\Property;
use App\Models\ProviderEvent;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\ResourceBlock;
use App\Models\Tenant;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Services\Payments\ProcessProviderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresDirectBookingConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'direct-booking.rate_limits.mutation_per_minute' => 1_000,
            'direct-booking.rate_limits.holds_per_hour' => 1_000,
        ]);
        putenv('DIRECT_BOOKING_COP_MP_TOKEN=cop-test-access-token');
        putenv('DIRECT_BOOKING_COP_MP_WEBHOOK=cop-test-webhook-secret');
    }

    protected function tearDown(): void
    {
        putenv('DIRECT_BOOKING_COP_MP_TOKEN');
        putenv('DIRECT_BOOKING_COP_MP_WEBHOOK');
        // DatabaseMigrations dismantles its isolated test schema via migrate:rollback,
        // which invokes the commercial-rules guard. Production rollback stays guarded
        // unless this explicit unit-test-only flag is set. A prior postgres
        // concurrency test may have cleared the phpunit.pgsql.xml value during its
        // own teardown, so set the flag immediately before the rollback and clear it
        // afterwards so it never leaks into the rest of the full CI suite.
        putenv('COMMERCIAL_TEST_TEARDOWN=1');
        try {
            parent::tearDown();
        } finally {
            putenv('COMMERCIAL_TEST_TEARDOWN');
        }
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL with pcntl is required for the direct-booking concurrency gate.');
        }
        $database = (string) config('database.connections.pgsql.database');
        if ($database !== 'inn_test' || (string) getenv('DB_DATABASE') !== 'inn_test') {
            $this->fail(
                'Refusing destructive DirectBooking PostgreSQL setup unless DB_DATABASE=inn_test (got database='
                .$database.').'
            );
        }
    }

    public function test_same_idempotency_key_and_body_replays_without_a_second_hold_or_payment_request(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property, 'idempotent-replay-lodge');
        $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);

        $beginKey = (string) Str::uuid();
        $beginBody = [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ];
        $begun = $this->postJson($base.'/orders', $beginBody, ['Idempotency-Key' => $beginKey])->assertCreated();
        $replayedBegin = $this->postJson($base.'/orders', $beginBody, ['Idempotency-Key' => $beginKey])
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($begun->getContent(), $replayedBegin->getContent());
        $this->assertDatabaseCount('direct_booking_orders', 1);

        $reference = $begun->json('data.order_reference');
        $auth = ['Authorization' => 'Bearer '.$begun->json('data.session_token')];
        $quoteKey = (string) Str::uuid();
        $quoteBody = $stay + ['expected_state_version' => 1];
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $quoteBody, $auth + [
            'Idempotency-Key' => $quoteKey,
        ])->assertOk();
        $replayedQuote = $this->postJson($base."/orders/{$reference}/quote", $quoteBody, $auth + [
            'Idempotency-Key' => $quoteKey,
        ])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($quoted->getContent(), $replayedQuote->getContent());
        $this->assertDatabaseCount('booking_quotes', 1);

        $holdKey = (string) Str::uuid();
        $holdBody = $this->holdBody($quoted, email: 'replay-guest@example.test');
        $held = $this->postJson($base."/orders/{$reference}/hold", $holdBody, $auth + [
            'Idempotency-Key' => $holdKey,
        ])->assertOk()->assertJsonPath('data.state', 'held');
        $replayedHold = $this->postJson($base."/orders/{$reference}/hold", $holdBody, $auth + [
            'Idempotency-Key' => $holdKey,
        ])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($held->getContent(), $replayedHold->getContent());

        $checkoutKey = (string) Str::uuid();
        $checkoutBody = [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ];
        $checkout = $this->postJson($base."/orders/{$reference}/checkout", $checkoutBody, $auth + [
            'Idempotency-Key' => $checkoutKey,
        ])->assertOk()->assertJsonPath('data.state', 'payment_pending');
        $replayedCheckout = $this->postJson($base."/orders/{$reference}/checkout", $checkoutBody, $auth + [
            'Idempotency-Key' => $checkoutKey,
        ])->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($checkout->getContent(), $replayedCheckout->getContent());

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('payment_requests', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_same_idempotency_key_and_different_body_returns_conflict(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property, 'idempotent-conflict-lodge');
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        $beginKey = (string) Str::uuid();
        $beginBody = [
            'locale' => 'en',
            'currency' => 'COP',
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_begin',
        ];
        $begun = $this->postJson($base.'/orders', $beginBody, ['Idempotency-Key' => $beginKey])->assertCreated();
        $conflictedBegin = $this->postJson($base.'/orders', [...$beginBody, 'locale' => 'es'], ['Idempotency-Key' => $beginKey])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSafeErrorEnvelope($conflictedBegin);
        $this->assertSame('en', $begun->json('data.locale'));

        $reference = $begun->json('data.order_reference');
        $auth = ['Authorization' => 'Bearer '.$begun->json('data.session_token')];
        $quoteKey = (string) Str::uuid();
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => $quoteKey,
        ])->assertOk();
        $this->postJson($base."/orders/{$reference}/quote", $this->stay($categoryItem->public_key, adults: 1) + [
            'expected_state_version' => 1,
        ], $auth + ['Idempotency-Key' => $quoteKey])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(80_000, $quoted->json('data.total.amount_minor'));

        $holdKey = (string) Str::uuid();
        $holdBody = $this->holdBody($quoted, email: 'conflict-guest@example.test');
        $held = $this->postJson($base."/orders/{$reference}/hold", $holdBody, $auth + [
            'Idempotency-Key' => $holdKey,
        ])->assertOk();
        $mutatedHold = $holdBody;
        $mutatedHold['guest']['first_name'] = 'Other';
        $this->postJson($base."/orders/{$reference}/hold", $mutatedHold, $auth + [
            'Idempotency-Key' => $holdKey,
        ])->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');

        $checkoutKey = (string) Str::uuid();
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'manual_bank_transfer',
        ], $auth + ['Idempotency-Key' => $checkoutKey])->assertOk();
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => $checkoutKey])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('payment_requests', 1);
        $this->assertDatabaseCount('direct_booking_orders', 1);
        $order = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertSame('Public', data_get($order->guest_contact_encrypted, 'first_name'));
        $this->assertSame('awaiting_manual_payment', $order->state->value);
    }

    public function test_two_concurrent_http_holds_for_the_last_unit_create_one_allocation(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property, 'last-unit-http-lodge', capacity: 1);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key, adults: 1);
        [$firstReference, $firstAuth, $firstQuoted] = $this->quotedOrder($base, $stay);
        [$secondReference, $secondAuth, $secondQuoted] = $this->quotedOrder($base, $stay);
        $firstHold = $this->holdBody($firstQuoted, email: 'last-unit-one@example.test');
        $secondHold = $this->holdBody($secondQuoted, email: 'last-unit-two@example.test');

        $results = $this->concurrently([
            fn (): string => $this->encodeHttp($this->postJson($base."/orders/{$firstReference}/hold", $firstHold, $firstAuth + [
                'Idempotency-Key' => (string) Str::uuid(),
            ])),
            fn (): string => $this->encodeHttp($this->postJson($base."/orders/{$secondReference}/hold", $secondHold, $secondAuth + [
                'Idempotency-Key' => (string) Str::uuid(),
            ])),
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $decoded = collect($results)->map(fn (array $result): array => json_decode((string) ($result['result'] ?? '{}'), true) ?? []);
        $winners = $decoded->filter(fn (array $payload): bool => ($payload['status'] ?? 0) < 300);
        $losers = $decoded->filter(fn (array $payload): bool => ($payload['status'] ?? 0) >= 400);
        $this->assertSame(1, $winners->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $losers->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('held', $winners->value('state'));
        $this->assertContains($losers->value('code'), ['unavailable', 'conflict']);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('payment_requests', 1);
    }

    public function test_late_approved_payment_after_hold_loss_is_paid_needs_review_without_overbook(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property, 'late-pay-lodge', capacity: 1);
        $connection = $this->copHostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key, adults: 1);
        [$reference, $auth, $quoted] = $this->quotedOrder($base, $stay);
        $held = $this->postJson($base."/orders/{$reference}/hold", $this->holdBody($quoted, email: 'late-pay@example.test'), $auth + [
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
        Artisan::call('reservation-holds:expire');
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Confirmed->value)->count());
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Tentative->value)->count());
        $allocationCountAfterLoss = DB::table('allocations')->count();

        app(TenantContext::class)->set($tenant, $membership);
        $attempt = PaymentAttempt::query()->sole();
        $this->storeApprovedPayment($connection, $attempt);
        app(ProcessProviderEvent::class)->handle($this->providerEvent($connection, 'cop-late-approved-1', 'cop-late-delivery'));

        $status = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'paid_needs_review');
        $confirmation = $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->assertSafeErrorEnvelope($confirmation);
        $this->assertArrayNotHasKey('confirmation_number', $status->json('data') ?? []);

        app(TenantContext::class)->set($tenant, $membership);
        $fresh = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertSame(DirectBookingOrderState::PaidNeedsReview, $fresh->state);
        $this->assertNotSame(ReservationStatus::Confirmed, $fresh->reservation->status);
        $this->assertNull($fresh->reservation->confirmed_at);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame($allocationCountAfterLoss, DB::table('allocations')->count());
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Confirmed->value)->count());
        $this->assertSame(0, DB::table('allocations')->where('status', AllocationStatus::Tentative->value)->count());
        $this->assertDatabaseCount('operational_tasks', 1);
    }

    public function test_wrong_or_missing_session_token_cannot_read_the_order_and_does_not_echo_the_token(): void
    {
        $this->requirePostgres();
        [$tenant, $property] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyCopProperty($property, 'opaque-token-lodge');
        $otherProperty = Property::factory()->create();
        [$otherSetting] = $this->launchReadyCopProperty($otherProperty, 'other-opaque-lodge');
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $otherBase = '/api/v1/direct-booking/properties/'.$otherSetting->public_slug;
        [$reference, $auth] = $this->quotedOrder($base, $this->stay($categoryItem->public_key));
        $token = substr($auth['Authorization'], 7);
        $wrongToken = 'WrongTokenWrongTokenWrongTokenWrongTokenWrongTokenWrongTokn12';

        $missing = $this->getJson($base.'/orders/'.$reference)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $wrong = $this->getJson($base.'/orders/'.$reference, ['Authorization' => 'Bearer '.$wrongToken])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $refAsBearer = $this->getJson($base.'/orders/'.$reference, ['Authorization' => 'Bearer '.$reference])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $otherProperty = $this->getJson($otherBase.'/orders/'.$reference, $auth)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'No', 'last_name' => 'Auth', 'email' => 'no-auth@example.test'],
            'consents' => [
                'terms' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
                'privacy' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
                'cancellation' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
                'no_show' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
                'marketing_consent' => ['version' => 1, 'checksum' => str_repeat('a', 64), 'accepted' => true],
            ],
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ], ['Idempotency-Key' => (string) Str::uuid(), 'Authorization' => 'Bearer '.$wrongToken])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        foreach ([$missing, $wrong, $refAsBearer, $otherProperty] as $response) {
            $this->assertSafeErrorEnvelope($response);
            $this->assertStringNotContainsString($token, (string) $response->getContent());
            $this->assertStringNotContainsString($wrongToken, (string) $response->getContent());
        }
        app(TenantContext::class)->set($tenant);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_error_envelopes_do_not_leak_secrets_traces_or_sql(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem, $resource] = $this->launchReadyCopProperty($property, 'safe-errors-lodge');
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = $this->stay($categoryItem->public_key);
        [$staleReference, $staleAuth, $staleQuoted] = $this->quotedOrder($base, $stay);
        $stale = $this->postJson($base."/orders/{$staleReference}/hold", $this->holdBody($staleQuoted, 1, 'stale-guest@example.test'), $staleAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'quote_stale');

        app(TenantContext::class)->set($tenant, $membership);
        ResourceBlock::query()->create([
            'resource_id' => $resource->id,
            'starts_at' => $stay['arrival_date'].' 00:00:00 '.$property->timezone,
            'ends_at' => $stay['departure_date'].' 00:00:00 '.$property->timezone,
            'reason' => 'sold_out',
        ]);
        [$unavailableReference, $unavailableAuth] = $this->beginOrder($base);
        $unavailable = $this->postJson($base."/orders/{$unavailableReference}/quote", $stay + ['expected_state_version' => 1], $unavailableAuth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('error.code', 'unavailable');

        $notFound = $this->getJson($base.'/orders/'.$staleReference)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        foreach ([$stale, $unavailable, $notFound] as $response) {
            $this->assertSafeErrorEnvelope($response);
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString($resource->id, $body);
            $this->assertStringNotContainsString('env:DIRECT_BOOKING_COP_MP_TOKEN', $body);
            $this->assertStringNotContainsString('cop-test-access-token', $body);
            $this->assertStringNotContainsString('cop-test-webhook-secret', $body);
        }
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_concurrent_state_version_updates_have_one_winner(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'state-race', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $order = $this->orderWithQuote($setting, $property);

        $results = $this->concurrently([
            fn (): string => app(DirectBookingStateMachine::class)->transition(
                $order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'state-race-command-0001',
            )->event->id,
            fn (): string => app(DirectBookingStateMachine::class)->transition(
                $order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'state-race-command-0002',
            )->event->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('direct_booking_order_events', 1);
        $this->assertDatabaseHas('direct_booking_orders', ['id' => $order->id, 'state' => 'quoted', 'state_version' => 2]);
        $this->cleanupContractFixture($order);
    }

    public function test_concurrent_same_retry_identity_records_once_and_replays(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'retry-race', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $order = $this->orderWithQuote($setting, $property);
        $operation = fn (): string => app(DirectBookingStateMachine::class)->transition(
            $order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'same-retry-command-0001',
        )->event->id;
        $results = $this->concurrently([$operation, $operation], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, collect($results)->pluck('result')->unique());
        $this->assertDatabaseCount('direct_booking_order_events', 1);
        $this->cleanupContractFixture($order);
    }

    public function test_two_anonymous_sessions_competing_for_the_last_unit_create_one_hold(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'last-unit-race', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $category = $this->category($property, 'room');
        Resource::factory()->create(['property_id' => $property->id, 'category_id' => $category->id, 'capacity' => 1]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id, 'name' => 'Last unit', 'currency' => 'USD', 'state' => 'draft', 'is_active' => true,
        ]);
        RateRule::query()->create(['rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 20_000]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $facts = [
            'property_id' => $property->id, 'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id,
            'starts_at' => now()->addDays(50)->startOfDay()->toIso8601String(),
            'ends_at' => now()->addDays(52)->startOfDay()->toIso8601String(),
            'adults' => 1, 'children' => 0,
        ];
        $firstQuote = app(BookingQuoteService::class)->create($facts);
        $secondQuote = app(BookingQuoteService::class)->create($facts);
        $first = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $second = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $first->forceFill(['booking_quote_id' => $firstQuote->id])->save();
        $second->forceFill(['booking_quote_id' => $secondQuote->id])->save();

        $results = $this->concurrently([
            fn (): string => app(CommitBookingQuote::class)->handle($firstQuote, null, ['first_name' => 'First'], source: 'direct')->id,
            fn (): string => app(CommitBookingQuote::class)->handle($secondQuote, null, ['first_name' => 'Second'], source: 'direct')->id,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('allocations', 1);
        $this->assertDatabaseCount('guests', 1);
        DB::table('direct_booking_order_events')->whereIn('direct_booking_order_id', [$first->id, $second->id])->delete();
        DB::table('direct_booking_orders')->whereIn('id', [$first->id, $second->id])->delete();
        $reservationIds = DB::table('reservations')->pluck('id');
        $guestIds = DB::table('reservations')->whereIn('id', $reservationIds)->pluck('primary_guest_id');
        DB::table('booking_quotes')->whereIn('id', [$firstQuote->id, $secondQuote->id])->update(['reservation_id' => null]);
        DB::table('reservations')->whereIn('id', $reservationIds)->update(['booking_quote_id' => null]);
        DB::table('reservations')->whereIn('id', $reservationIds)->delete();
        DB::table('guests')->whereIn('id', $guestIds)->delete();
        DB::table('booking_quotes')->whereIn('id', [$firstQuote->id, $secondQuote->id])->delete();
        DB::table('rate_plans')->where('id', $plan->id)->delete();
    }

    public function test_revoke_wins_against_a_concurrent_stale_rotation_without_session_resurrection(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'token-race', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $order = $this->orderWithQuote($setting, $property);

        $results = $this->concurrently([
            function () use ($order): string {
                app(DirectBookingTokenService::class)->revoke($order);

                return 'revoked';
            },
            function () use ($order): string {
                usleep(150_000);

                return app(DirectBookingTokenService::class)->rotate($order)['token'];
            },
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertFalse($results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('AuthenticationException', $results[1]['error'] ?? '');
        $this->assertNotNull($order->fresh()->revoked_at);
        $this->cleanupContractFixture($order);
    }

    public function test_expired_recovery_racing_maintenance_never_produces_an_active_scrubbed_order(): void
    {
        $this->assertMaintenanceTransitionRace(
            DirectBookingOrderState::Started,
            DirectBookingTransitionAuthority::Recovery,
            'pii-recovery-race-0001',
        );
    }

    public function test_late_payment_review_racing_maintenance_never_produces_a_review_scrubbed_order(): void
    {
        $this->assertMaintenanceTransitionRace(
            DirectBookingOrderState::PaidNeedsReview,
            DirectBookingTransitionAuthority::ProviderLookup,
            'pii-payment-race-00001',
        );
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok: bool, result?: string, error?: string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-direct-booking-race-'.Str::random(12);
        mkdir($directory, 0700, true);
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($barrier === false) {
            $this->fail('Unable to create concurrency barrier.');
        }
        $children = [];
        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork concurrency worker.');
            }
            if ($pid === 0) {
                fclose($barrier[0]);
                fread($barrier[1], 1);
                try {
                    DB::purge();
                    DB::reconnect();
                    app(TenantContext::class)->set($tenant, $membership);
                    $payload = ['ok' => true, 'result' => $operation()];
                } catch (Throwable $exception) {
                    $payload = ['ok' => false, 'error' => $exception::class.': '.$exception->getMessage()];
                }
                file_put_contents("{$directory}/{$index}.json", json_encode($payload, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }
        fclose($barrier[1]);
        fwrite($barrier[0], str_repeat('1', count($operations)));
        fclose($barrier[0]);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        DB::purge();
        DB::reconnect();

        $results = [];
        foreach (array_keys($operations) as $index) {
            $results[] = json_decode((string) file_get_contents("{$directory}/{$index}.json"), true, flags: JSON_THROW_ON_ERROR);
            unlink("{$directory}/{$index}.json");
        }
        rmdir($directory);

        return $results;
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('PostgreSQL with pcntl is required for the direct-booking concurrency gate.');
        }
        $this->assertSame('inn_test', (string) getenv('DB_DATABASE'));
        $this->assertSame('inn_test', (string) config('database.connections.pgsql.database'));
    }

    /** @return array{DirectBookingPropertySetting, DirectBookingPublicItem, resource} */
    private function launchReadyCopProperty($property, string $slug, int $capacity = 4): array
    {
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => $slug,
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
            'name' => 'Casa Race Internal '.$slug,
            'code' => 'ROOM-RACE-'.strtoupper(substr(hash('sha256', $slug), 0, 8)),
            'capacity' => $capacity,
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
            'name' => 'Public COP race '.$slug,
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

    private function copHostedConnection(string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'property_id' => $propertyId,
            'name' => 'COP Checkout Pro race',
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
                'fixture' => [
                    'preference_id' => 'pref-cop-race',
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

    /** @param array<string, mixed> $stay @return array{0: string, 1: array{Authorization: string}, 2: TestResponse} */
    private function quotedOrder(string $base, array $stay): array
    {
        [$reference, $auth] = $this->beginOrder($base);
        $quoted = $this->postJson($base."/orders/{$reference}/quote", $stay + ['expected_state_version' => 1], $auth + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertOk();

        return [$reference, $auth, $quoted];
    }

    /** @return array<string, mixed> */
    private function holdBody(TestResponse $quoted, int $expectedStateVersion = 2, string $email = 'public@example.test'): array
    {
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => true],
        ])->all();

        return [
            'expected_state_version' => $expectedStateVersion,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => $email, 'phone' => '+573001112233'],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ];
    }

    private function storeApprovedPayment(IntegrationConnection $connection, PaymentAttempt $attempt): void
    {
        $configuration = $connection->fresh()->configuration ?? [];
        $configuration['fixture'] = [
            'preference_id' => data_get($configuration, 'fixture.preference_id', 'pref-cop-race'),
            'payment' => [
                'id' => 'cop-late-approved-1',
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

    private function encodeHttp(TestResponse $response): string
    {
        return json_encode([
            'status' => $response->status(),
            'state' => $response->json('data.state'),
            'code' => $response->json('error.code'),
        ], JSON_THROW_ON_ERROR);
    }

    private function assertSafeErrorEnvelope(TestResponse $response): void
    {
        $payload = $response->json();
        $this->assertSame(['error'], array_keys($payload));
        $this->assertSame(
            ['code', 'message', 'correlation_id', 'retryable'],
            array_keys($payload['error']),
        );
        $body = (string) $response->getContent();
        $this->assertDoesNotMatchRegularExpression('/Bearer\\s+[A-Za-z0-9._\\-]{8,}/', $body);
        $this->assertStringNotContainsString('stack', strtolower($body));
        $this->assertStringNotContainsString('trace', strtolower($body));
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('select ', strtolower($body));
        $this->assertStringNotContainsString('secret_reference', $body);
        $this->assertStringNotContainsString('access_token', $body);
        $this->assertStringNotContainsString('APP_KEY', $body);
        $this->assertStringNotContainsString('file_put_contents', $body);
        $this->assertStringNotContainsString('/app/', $body);
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

    private function assertMaintenanceTransitionRace(
        DirectBookingOrderState $target,
        DirectBookingTransitionAuthority $authority,
        string $retryIdentity,
    ): void {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'pii-race-'.str_replace('_', '-', $target->value),
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
        ]);
        $order = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $order->forceFill([
            'state' => DirectBookingOrderState::Expired,
            'retained_until' => now()->subMinute(),
            'guest_contact_encrypted' => ['email' => 'race@example.test'],
            'guest_contact_checksum' => str_repeat('a', 64),
        ])->save();

        $results = $this->concurrently([
            fn (): string => (string) Artisan::call('direct-booking:maintain', [
                '--tenant' => $tenant->id,
                '--cleanup' => true,
            ]),
            fn (): string => app(DirectBookingStateMachine::class)->transition(
                $order,
                $target,
                $authority,
                1,
                $retryIdentity,
            )->order->state->value,
        ], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $fresh = $order->fresh();
        $this->assertFalse(
            $fresh->state === $target && $fresh->pii_scrubbed_at !== null,
            json_encode($results, JSON_THROW_ON_ERROR),
        );
        if ($fresh->state === $target) {
            $this->assertNull($fresh->pii_scrubbed_at);
            $this->assertNotNull($fresh->guest_contact_encrypted);
            $this->assertTrue($results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        } else {
            $this->assertSame(DirectBookingOrderState::Expired, $fresh->state);
            $this->assertNotNull($fresh->pii_scrubbed_at);
            $this->assertFalse($results[1]['ok'], json_encode($results, JSON_THROW_ON_ERROR));
        }

        DB::table('direct_booking_order_events')->where('direct_booking_order_id', $order->id)->delete();
        DB::table('direct_booking_orders')->where('id', $order->id)->delete();
    }

    private function orderWithQuote(DirectBookingPropertySetting $setting, Property $property): DirectBookingOrder
    {
        $category = $this->category($property, 'room');
        $plan = RatePlan::query()->create([
            'property_id' => $property->id,
            'name' => 'Concurrency '.Str::ulid(),
            'currency' => 'USD',
            'state' => 'draft',
            'is_active' => true,
        ]);
        $quote = BookingQuote::query()->create([
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'starts_at' => now()->addDays(10),
            'ends_at' => now()->addDays(12),
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'currency' => 'USD',
            'subtotal_minor' => 20_000,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 20_000,
            'inputs' => [],
            'calculation_snapshot' => [],
            'checksum' => str_repeat('a', 64),
            'status' => BookingQuoteStatus::Pending,
            'expires_at' => now()->addMinutes(20),
        ]);
        $order = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $order->forceFill(['booking_quote_id' => $quote->id])->save();

        return $order;
    }

    private function cleanupContractFixture(DirectBookingOrder $order): void
    {
        $quote = BookingQuote::query()->findOrFail($order->booking_quote_id);
        DB::table('direct_booking_order_events')->where('direct_booking_order_id', $order->id)->delete();
        DB::table('direct_booking_orders')->where('id', $order->id)->delete();
        DB::table('booking_quotes')->where('id', $quote->id)->delete();
        DB::table('rate_plans')->where('id', $quote->rate_plan_id)->delete();
    }
}
