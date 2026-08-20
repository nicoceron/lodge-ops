<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Enums\PaymentRequestPurpose;
use App\Exceptions\CommercialWorkflowException;
use App\Exceptions\DirectBookingContractException;
use App\Models\BookingQuote;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPaymentInstruction;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\Program;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\DirectBooking\CloudflareTurnstileVerifier;
use App\Services\DirectBooking\DirectBookingConsentRecorder;
use App\Services\DirectBooking\DirectBookingLaunchReadinessEvaluator;
use App\Services\DirectBooking\DirectBookingPublicUrl;
use App\Services\DirectBooking\DirectBookingSafeProjection;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Services\DirectBooking\IssueDirectBookingPaymentRequest;
use App\Services\Payments\IssuePaymentRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class DirectBookingContractTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    }

    public function test_tokens_are_hashed_property_bound_rotated_revoked_and_expiring(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = $this->setting($property->id);
        $issued = app(DirectBookingTokenService::class)->issue(
            $setting,
            'es-AR',
            'usd',
            ['utm_source' => 'newsletter', 'email' => 'must-not-persist@example.test', 'landing_path' => '/book'],
            '203.0.113.49',
        );
        $order = $issued['order'];
        $this->assertSame(120, $setting->session_ttl_minutes);
        $this->assertSame(30, $setting->retention_days);
        $this->assertSame(120, (int) $order->created_at->diffInMinutes($order->session_expires_at));
        $this->assertSame(64, strlen($issued['token']));
        $this->assertSame(64, strlen($issued['recovery_token']));
        $this->assertSame(hash('sha256', $issued['token']), $order->getRawOriginal('token_hash'));
        $this->assertSame(hash('sha256', $issued['recovery_token']), $order->getRawOriginal('recovery_token_hash'));
        $this->assertNotSame($issued['token'], $order->getRawOriginal('token_hash'));
        $this->assertSame(['utm_source' => 'newsletter', 'landing_path' => '/book'], $order->attribution);
        $this->assertNotNull($order->getRawOriginal('ip_prefix_hash'));
        $this->assertSame($order->id, app(DirectBookingTokenService::class)->resolve($issued['token'], $property->id)->id);

        [, $otherProperty] = $this->tenantEnvironment();
        try {
            app(DirectBookingTokenService::class)->resolve($issued['token'], $otherProperty->id);
            $this->fail('A token must remain property-bound.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        app(TenantContext::class)->set($tenant, $membership);
        $rotated = app(DirectBookingTokenService::class)->rotate($order);
        try {
            app(DirectBookingTokenService::class)->resolve($issued['token'], $property->id);
            $this->fail('The old token must be invalid immediately after rotation.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($order->id, app(DirectBookingTokenService::class)->resolve($rotated['token'], $property->id)->id);
        app(DirectBookingTokenService::class)->revoke($rotated['order']);
        try {
            app(DirectBookingTokenService::class)->rotate($rotated['order']);
            $this->fail('A stale pre-revocation model must not resurrect a revoked session.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull($rotated['order']->fresh()->revoked_at);
        try {
            app(DirectBookingTokenService::class)->resolve($rotated['token'], $property->id);
            $this->fail('A revoked token must fail generically.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        $fresh = app(DirectBookingTokenService::class)->issue($setting, 'es-AR', 'USD');
        $fresh['order']->forceFill(['expires_at' => now()->subSecond(), 'session_expires_at' => now()->subSecond()])->save();
        try {
            app(DirectBookingTokenService::class)->rotate($fresh['order']);
            $this->fail('An expired session cannot be extended through token rotation.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(DirectBookingTokenService::class)->resolve($fresh['token'], $property->id);
            $this->fail('An expired session token must fail while recovery remains available.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
        $recovered = app(DirectBookingTokenService::class)->recover($fresh['recovery_token'], $property->id);
        $this->assertTrue($recovered['order']->session_expires_at->isFuture());
        $this->assertTrue($recovered['order']->recovery_expires_at->isFuture());
        $this->assertSame($fresh['order']->id, app(DirectBookingTokenService::class)->resolve($recovered['token'], $property->id)->id);
        try {
            app(DirectBookingTokenService::class)->recover($fresh['recovery_token'], $property->id);
            $this->fail('Recovery credentials must rotate exactly once.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_state_machine_freezes_authority_replay_version_hold_and_late_payment_rules(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$order, $quote] = $this->orderWithQuote($property);
        $states = app(DirectBookingStateMachine::class);

        $quoted = $states->transition($order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'quote-command-0001');
        $this->assertFalse($quoted->replayed);
        $this->assertSame(2, $quoted->order->state_version);
        $replay = $states->transition($order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'quote-command-0001');
        $this->assertTrue($replay->replayed);
        $this->assertDatabaseCount('direct_booking_order_events', 1);

        try {
            $states->transition($order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'quote-command-0001', ['reason_code' => 'different']);
            $this->fail('Same retry identity with different canonical facts must conflict.');
        } catch (DirectBookingContractException $exception) {
            $this->assertSame(DirectBookingErrorCode::IdempotencyConflict, $exception->errorCode);
        }
        try {
            $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::ProviderLookup, 2, 'invalid-authority-01');
            $this->fail('A provider lookup cannot create inventory holds.');
        } catch (DirectBookingContractException $exception) {
            $this->assertSame(DirectBookingErrorCode::Conflict, $exception->errorCode);
        }
        try {
            $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory, 1, 'stale-version-0001');
            $this->fail('A stale state version must conflict.');
        } catch (DirectBookingContractException $exception) {
            $this->assertSame(DirectBookingErrorCode::Conflict, $exception->errorCode);
        }

        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id, source: 'direct');
        $order->forceFill(['reservation_id' => $reservation->id])->save();
        $held = $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory, 2, 'hold-command-00001');
        $this->assertTrue($reservation->hold_expires_at->equalTo($held->order->hold_expires_at));
        try {
            app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::Deposit, null, null, null);
            $this->fail('The general staff payment-request rule must remain confirmed/checked-in only.');
        } catch (CommercialWorkflowException) {
            $this->addToAssertionCount(1);
        }
        $issued = app(IssueDirectBookingPaymentRequest::class)->handle($held->order, 3, 'checkout-command-01');
        $this->assertFalse($issued['replayed']);
        $this->assertTrue($issued['request']->expires_at->equalTo($held->order->hold_expires_at));
        $this->assertSame($quote->checksum, data_get($issued['request']->calculation_snapshot, 'quote_checksum'));
        $extended = $states->transition($order, DirectBookingOrderState::PaymentPending, DirectBookingTransitionAuthority::PaymentOrchestrator, 4, 'extend-command-0001', ['hold_extension_minutes' => 15]);
        $this->assertTrue($extended->order->hold_expires_at->equalTo($reservation->fresh()->hold_expires_at));
        $this->assertTrue($extended->order->hold_expires_at->equalTo($issued['request']->fresh()->expires_at));
        $this->assertTrue($extended->order->checkout_expires_at->equalTo($extended->order->hold_expires_at));
        $this->assertLessThanOrEqual(45, (int) $extended->order->held_at->diffInMinutes($extended->order->hold_expires_at));
        try {
            $states->transition($order, DirectBookingOrderState::PaymentPending, DirectBookingTransitionAuthority::PaymentOrchestrator, 5, 'extend-command-0002', ['hold_extension_minutes' => 1]);
            $this->fail('Hosted checkout may extend a hold only once.');
        } catch (DirectBookingContractException $exception) {
            $this->assertSame(DirectBookingErrorCode::Conflict, $exception->errorCode);
        }

        $expired = $states->transition($order, DirectBookingOrderState::Expired, DirectBookingTransitionAuthority::Scheduler, 5, 'expire-command-0001');
        $late = $states->transition($order, DirectBookingOrderState::PaidNeedsReview, DirectBookingTransitionAuthority::ProviderLookup, 6, 'late-payment-00001', ['reason_code' => 'late_authoritative_payment']);
        $this->assertSame(DirectBookingOrderState::PaidNeedsReview, $late->order->state);
        $this->assertNotNull($late->order->paid_at);
        $this->assertSame(DirectBookingErrorCode::PaidNeedsReview, $late->order->safe_failure_code);
        $this->assertSame(DirectBookingOrderState::Expired, $expired->event->to_state);
        $this->assertSame(DirectBookingOrderState::PaymentPending, $extended->event->from_state);
    }

    public function test_manual_payment_branch_requires_evidence_scanner_finance_and_reservation_authorities(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$order, $quote] = $this->orderWithQuote($property);
        $states = app(DirectBookingStateMachine::class);
        $order = $states->transition($order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'manual-flow-00000000')->order;
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id, source: 'direct');
        $order->forceFill(['reservation_id' => $reservation->id])->save();
        $order = $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory, 2, 'manual-flow-00000001')->order;
        $steps = [
            [DirectBookingOrderState::AwaitingManualPayment, DirectBookingTransitionAuthority::PaymentOrchestrator],
            [DirectBookingOrderState::EvidencePending, DirectBookingTransitionAuthority::GuestEvidence],
            [DirectBookingOrderState::FinanceReview, DirectBookingTransitionAuthority::EvidenceScanner],
            [DirectBookingOrderState::Confirmed, DirectBookingTransitionAuthority::Finance],
        ];
        foreach ($steps as $index => [$state, $authority]) {
            $result = $states->transition($order, $state, $authority, $index + 3, 'manual-flow-'.str_pad((string) ($index + 2), 8, '0', STR_PAD_LEFT));
            $order = $result->order;
        }
        $this->assertSame(DirectBookingOrderState::Confirmed, $order->state);
        $this->assertDatabaseCount('direct_booking_order_events', 6);
    }

    public function test_accepted_manual_evidence_survives_inventory_expiry_for_finance_refund(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$order, $quote] = $this->orderWithQuote($property);
        $states = app(DirectBookingStateMachine::class);
        $order = $states->transition($order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'late-manual-000001')->order;
        $reservation = app(CommitBookingQuote::class)->handle($quote, Guest::factory()->create()->id, source: 'direct');
        $order->forceFill(['reservation_id' => $reservation->id])->save();
        $order = $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory, 2, 'late-manual-000002')->order;
        $order = $states->transition($order, DirectBookingOrderState::AwaitingManualPayment, DirectBookingTransitionAuthority::PaymentOrchestrator, 3, 'late-manual-000003')->order;
        $order = $states->transition($order, DirectBookingOrderState::EvidencePending, DirectBookingTransitionAuthority::GuestEvidence, 4, 'late-manual-000004')->order;
        $order->forceFill(['hold_expires_at' => now()->subSecond()])->save();
        $reservation->forceFill(['hold_expires_at' => now()->subSecond()])->save();

        Artisan::call('direct-booking:maintain', ['--tenant' => $order->tenant_id]);
        app(TenantContext::class)->set($tenant, $membership);
        $review = $order->fresh();
        $this->assertSame(DirectBookingOrderState::FinanceReview, $review->state);
        $this->assertDatabaseMissing('direct_booking_orders', ['id' => $order->id, 'state' => 'expired']);
        $refunded = $states->transition($review, DirectBookingOrderState::Refunded, DirectBookingTransitionAuthority::Refund, 6, 'late-manual-refund-1');
        $this->assertSame(DirectBookingOrderState::Refunded, $refunded->order->state);
    }

    public function test_consent_snapshots_are_separate_immutable_and_checksum_bound(): void
    {
        [, $property] = $this->tenantEnvironment();
        $order = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD')['order'];
        $decisions = [];
        foreach ([
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
        ] as $kind) {
            $publication = $this->publication($property->id, $kind, 'es-AR', withMedia: false);
            $decisions[$kind->value] = ['publication_id' => $publication->id, 'accepted' => $kind !== DirectBookingPublicationKind::MarketingConsent];
        }
        $recorded = app(DirectBookingConsentRecorder::class)->record($order, $decisions, '203.0.113.25');
        $this->assertCount(5, $recorded->consents);
        $this->assertFalse($recorded->consents->firstWhere('kind', DirectBookingPublicationKind::MarketingConsent)->accepted);
        $this->assertNotNull($recorded->consent_checksum);
        $this->assertDatabaseMissing('direct_booking_order_consents', ['ip_prefix_hash' => '203.0.113.0']);

        $replayed = app(DirectBookingConsentRecorder::class)->record($order, $decisions, '198.51.100.14');
        $this->assertSame($recorded->consent_checksum, $replayed->consent_checksum);
        $this->assertDatabaseCount('direct_booking_order_consents', 5);

        $decisions[DirectBookingPublicationKind::MarketingConsent->value]['accepted'] = true;
        try {
            app(DirectBookingConsentRecorder::class)->record($order, $decisions, '198.51.100.14');
            $this->fail('A replay may not mutate an immutable consent decision.');
        } catch (DirectBookingContractException $exception) {
            $this->assertSame(DirectBookingErrorCode::Conflict, $exception->errorCode);
        }
    }

    public function test_projection_drops_internal_resource_identity_counts_notes_and_provider_metadata(): void
    {
        [, $property] = $this->tenantEnvironment();
        $setting = $this->setting($property->id);
        $category = $this->category($property, 'room');
        Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 4,
        ]);
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id, 'kind' => 'category', 'resource_category_id' => $category->id, 'is_enabled' => true,
        ]);
        $program = Program::query()->create([
            'property_id' => $property->id, 'name' => 'Riding', 'default_duration_minutes' => 120,
            'capacity' => 4, 'price_minor' => 50_000, 'currency' => 'USD', 'is_active' => true,
        ]);
        $programItem = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id, 'kind' => 'program', 'program_id' => $program->id, 'is_enabled' => true,
        ]);
        $this->publication($property->id, DirectBookingPublicationKind::Property, 'es-AR', withMedia: true);
        $this->publication($property->id, DirectBookingPublicationKind::Category, 'es-AR', $item->id, withMedia: true);
        $this->publication($property->id, DirectBookingPublicationKind::Program, 'es-AR', $programItem->id, withMedia: true);
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id, 'currency' => 'USD', 'method' => 'hosted_checkout',
            'is_enabled' => true, 'public_configuration' => ['label' => 'Secure checkout'],
        ]);
        $projection = app(DirectBookingSafeProjection::class)->property($setting, 'es-AR');
        $availability = app(DirectBookingSafeProjection::class)->availability($setting, [
            'categories' => [[
                'id' => $category->id, 'name' => 'Internal category', 'available_units' => 9,
                'maximum_occupancy' => 4, 'available' => true, 'staff_note' => 'do not expose',
            ]],
            'resources' => [[
                'id' => 'internal-room-id', 'name' => 'Room 7', 'capacity' => 4,
                'provider_metadata' => ['secret' => 'never'], 'available' => true,
            ]],
            'programs' => [['id' => $program->id, 'available' => false, 'remaining_capacity' => 3]],
        ]);
        $json = json_encode([$projection, $availability], JSON_THROW_ON_ERROR);
        foreach ([$category->id, 'internal-room-id', 'Room 7', 'available_units', 'maximum_occupancy', 'staff_note', 'provider_metadata', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame([
            ['key' => $item->public_key, 'kind' => 'category', 'bookable' => true],
            ['key' => $programItem->public_key, 'kind' => 'program', 'bookable' => false],
        ], $availability);
        $this->assertSame([['method' => 'hosted_checkout', 'currency' => 'USD']], $projection['payment_capabilities']);
        $this->assertSame([
            ['kind' => 'category', 'name' => 'Category'],
            ['kind' => 'program', 'name' => 'Program'],
        ], collect($projection['bookables'])->map(fn (array $bookable): array => [
            'kind' => $bookable['kind'], 'name' => $bookable['name'],
        ])->values()->all());
        $this->assertSame('https://example.test/contact', $projection['accessible_fallback_url']);
        $this->assertFalse(app(DirectBookingPublicUrl::class)->isSafeHttps('https://127.0.0.1/contact'));
        $this->assertFalse(app(DirectBookingPublicUrl::class)->isSafeHttps('https://localhost/contact'));
        $this->assertFalse(app(DirectBookingPublicUrl::class)->isSafeHttps('https://example.test/contact?guest=1'));
    }

    public function test_turnstile_is_server_validated_with_action_hostname_and_idempotency(): void
    {
        config([
            'direct-booking.turnstile_secret' => 'secret-value',
            'direct-booking.turnstile_allowed_hostnames' => ['book.example.test'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true, 'challenge_ts' => now()->toIso8601String(), 'hostname' => 'book.example.test',
                'action' => 'direct_booking_hold', 'cdata' => 'opaque-client-fact', 'error-codes' => [],
            ]),
        ]);
        $idempotency = 'f7cb2e4b-2e7d-4a10-91c0-f4ac53c14711';
        $result = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', '203.0.113.8', 'direct_booking_hold', $idempotency);
        $this->assertTrue($result->valid);
        Http::assertSent(fn ($request): bool => $request['secret'] === 'secret-value'
            && $request['response'] === 'single-use-token'
            && $request['remoteip'] === '203.0.113.8'
            && $request['idempotency_key'] === $idempotency
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        $wrongAction = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', null, 'direct_booking_begin', 'f175ed23-52df-4bb2-86ec-e41a246e9fd0');
        $this->assertFalse($wrongAction->valid);
        Http::fake();
        $invalidIdempotency = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', null, 'direct_booking_hold', 'not-a-uuid');
        $this->assertFalse($invalidIdempotency->valid);
        Http::assertNothingSent();
        config(['direct-booking.turnstile_allowed_hostnames' => []]);
        $missingAllowlist = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', null, 'direct_booking_hold', $idempotency);
        $this->assertFalse($missingAllowlist->valid);
        Http::assertNothingSent();

        config(['direct-booking.turnstile_allowed_hostnames' => ['book.example.test']]);
        Http::fake(fn () => throw new ConnectionException('simulated timeout'));
        $unavailable = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', null, 'direct_booking_hold', $idempotency);
        $this->assertFalse($unavailable->valid);
        $this->assertSame(['verification-unavailable'], $unavailable->safeErrorCodes);
    }

    public function test_launch_readiness_fails_closed_until_content_commercial_payment_and_accessibility_inputs_exist(): void
    {
        config([
            'direct-booking.turnstile_secret' => null,
            'direct-booking.turnstile_allowed_hostnames' => [],
        ]);
        [, $property] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'readiness-property',
            'direct_booking_enabled' => true,
            'bot_verification_required' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'accessible_fallback_url' => 'https://example.test/contact',
        ]);
        $this->assertTrue($setting->bot_verification_required);
        $this->assertFalse(app(CloudflareTurnstileVerifier::class)->configurationReady());
        $blocked = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting);
        $this->assertFalse($blocked->ready);
        $this->assertContains('bookable_projection_missing', $blocked->blockingReasons);
        $this->assertContains('commercial_rules_missing:USD', $blocked->blockingReasons);
        $this->assertContains('payment_capability_missing:USD', $blocked->blockingReasons);
        $this->assertContains('bot_verification_not_ready', $blocked->blockingReasons);

        $category = $this->category($property, 'room');
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id, 'kind' => 'category', 'resource_category_id' => $category->id, 'is_enabled' => true,
        ]);
        foreach ([
            DirectBookingPublicationKind::Property,
            DirectBookingPublicationKind::Terms,
            DirectBookingPublicationKind::Privacy,
            DirectBookingPublicationKind::Cancellation,
            DirectBookingPublicationKind::NoShow,
            DirectBookingPublicationKind::MarketingConsent,
            DirectBookingPublicationKind::BankTransferInstructions,
        ] as $kind) {
            $this->publication($property->id, $kind, 'en', withMedia: $kind === DirectBookingPublicationKind::Property);
        }
        try {
            DirectBookingPublication::query()->create([
                'property_id' => $property->id, 'public_item_id' => $item->id,
                'kind' => DirectBookingPublicationKind::Program, 'locale' => 'en', 'version' => 1,
                'state' => DirectBookingPublicationState::Published, 'title' => 'Wrong kind', 'summary' => 'Wrong kind copy.',
                'body' => 'Wrong kind.', 'effective_at' => now()->subDay(), 'published_at' => now(),
            ]);
            $this->fail('Program copy must not be associated with a category item.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $exactCopyBlocked = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertContains("item_publication_missing:{$item->public_key}:en", $exactCopyBlocked->blockingReasons);
        $this->publication($property->id, DirectBookingPublicationKind::Category, 'en', $item->id, withMedia: true);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id, 'name' => 'Public USD', 'currency' => 'USD',
            'state' => 'draft', 'is_active' => true,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id, 'resource_category_id' => $category->id, 'amount_minor' => 10_000,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $instructions = DirectBookingPublication::query()
            ->where('property_id', $property->id)->where('kind', DirectBookingPublicationKind::BankTransferInstructions)->sole();
        $capability = DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id, 'currency' => 'USD', 'method' => 'manual_bank_transfer',
            'is_enabled' => true, 'instructions_publication_id' => $instructions->id,
        ]);
        DirectBookingPaymentInstruction::query()->create([
            'property_id' => $property->id,
            'direct_booking_payment_capability_id' => $capability->id,
            'publication_id' => $instructions->id,
            'locale' => 'en',
        ]);

        $unsupported = IntegrationConnection::query()->create([
            'name' => 'Unsupported public provider', 'type' => 'payment', 'status' => 'connected',
            'configuration' => ['provider' => 'unsupported', 'provider_account' => 'account-1', 'charge_currency' => 'USD'],
            'secret_reference' => 'env:UNUSED_PUBLIC_PROVIDER_TOKEN',
        ]);
        $hosted = DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id, 'currency' => 'USD', 'method' => 'hosted_checkout',
            'is_enabled' => true, 'provider_connection_id' => $unsupported->id,
        ]);
        $providerBlocked = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertContains('hosted_checkout_not_ready:USD', $providerBlocked->blockingReasons);
        $hosted->update(['is_enabled' => false]);

        config([
            'direct-booking.turnstile_secret' => 'launch-secret',
            'direct-booking.turnstile_allowed_hostnames' => ['book.example.test'],
        ]);
        $ready = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertTrue($ready->ready, implode(', ', $ready->blockingReasons));
        $this->assertSame([], $ready->blockingReasons);

        config(['direct-booking.turnstile_allowed_hostnames' => ['not a hostname']]);
        $invalidBotConfiguration = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertContains('bot_verification_not_ready', $invalidBotConfiguration->blockingReasons);
        config(['direct-booking.turnstile_allowed_hostnames' => ['127.0.0.1', 'localhost']]);
        $localBotConfiguration = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertContains('bot_verification_not_ready', $localBotConfiguration->blockingReasons);
    }

    public function test_singleton_maintenance_expires_sessions_and_scrubs_pii_without_deleting_ledger(): void
    {
        [, $property] = $this->tenantEnvironment();
        $issued = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD');
        $issued['order']->forceFill([
            'expires_at' => now()->subMinute(),
            'session_expires_at' => now()->subMinute(),
            'retained_until' => now()->subMinute(),
            'guest_contact_encrypted' => ['email' => 'private@example.test'],
            'guest_contact_checksum' => str_repeat('a', 64),
        ])->save();

        $this->assertSame(0, Artisan::call('direct-booking:maintain', ['--tenant' => $issued['order']->tenant_id]));
        $expired = $issued['order']->fresh();
        $this->assertSame(DirectBookingOrderState::Expired, $expired->state);
        $this->assertDatabaseCount('direct_booking_order_events', 1);

        $this->assertSame(0, Artisan::call('direct-booking:maintain', ['--tenant' => $expired->tenant_id, '--cleanup' => true]));
        $scrubbed = $expired->fresh();
        $this->assertNull($scrubbed->guest_contact_encrypted);
        $this->assertNull($scrubbed->attribution);
        $this->assertNull($scrubbed->getRawOriginal('recovery_token_hash'));
        $this->assertNotNull($scrubbed->revoked_at);
        $this->assertNotNull($scrubbed->pii_scrubbed_at);
        $this->assertDatabaseCount('direct_booking_orders', 1);
        $this->assertDatabaseCount('direct_booking_order_events', 2);
        $this->assertDatabaseHas('direct_booking_order_events', ['direct_booking_order_id' => $scrubbed->id, 'event_type' => 'pii_scrubbed']);
    }

    public function test_pii_maintenance_preserves_confirmation_time_and_scrubs_already_revoked_rows(): void
    {
        [, $property] = $this->tenantEnvironment();
        $issued = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD');
        $confirmedAt = now()->subDays(10)->startOfSecond();
        $issued['order']->forceFill([
            'state' => DirectBookingOrderState::Confirmed,
            'confirmed_at' => $confirmedAt,
            'retained_until' => now()->subMinute(),
            'revoked_at' => now()->subDay(),
            'guest_contact_encrypted' => ['phone' => '+000000000'],
            'guest_contact_checksum' => str_repeat('b', 64),
            'ip_prefix_hash' => str_repeat('c', 64),
        ])->save();

        Artisan::call('direct-booking:maintain', ['--tenant' => $issued['order']->tenant_id, '--cleanup' => true]);
        $scrubbed = $issued['order']->fresh();
        $this->assertTrue($confirmedAt->equalTo($scrubbed->confirmed_at));
        $this->assertNull($scrubbed->guest_contact_encrypted);
        $this->assertNull($scrubbed->guest_contact_checksum);
        $this->assertNull($scrubbed->ip_prefix_hash);
        $this->assertNotNull($scrubbed->pii_scrubbed_at);
        $this->assertDatabaseHas('direct_booking_order_events', ['direct_booking_order_id' => $scrubbed->id, 'event_type' => 'pii_scrubbed']);
    }

    public function test_pii_maintenance_cleans_unshared_provisional_guest_and_defers_shared_guest(): void
    {
        [, $property] = $this->tenantEnvironment();

        [$cleanOrder, $cleanQuote] = $this->orderWithQuote($property);
        $cleanGuest = Guest::factory()->create([
            'first_name' => 'Provisional',
            'email' => 'provisional@example.test',
            'document_type' => 'passport',
            'document_number' => 'PRIVATE-123',
        ]);
        $cleanReservation = app(CommitBookingQuote::class)->handle($cleanQuote, $cleanGuest->id, source: 'direct');
        $cleanReservation->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        $cleanOrder->forceFill([
            'state' => DirectBookingOrderState::Expired,
            'reservation_id' => $cleanReservation->id,
            'retained_until' => now()->subMinute(),
        ])->save();

        [$deferredOrder, $deferredQuote] = $this->orderWithQuote($property);
        $sharedGuest = Guest::factory()->create(['first_name' => 'Shared', 'email' => 'shared@example.test']);
        $deferredReservation = app(CommitBookingQuote::class)->handle($deferredQuote, $sharedGuest->id, source: 'direct');
        $deferredReservation->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $sharedGuest->id,
            'source' => 'staff',
        ]);
        $deferredOrder->forceFill([
            'state' => DirectBookingOrderState::Expired,
            'reservation_id' => $deferredReservation->id,
            'retained_until' => now()->subMinute(),
        ])->save();

        Artisan::call('direct-booking:maintain', ['--tenant' => $cleanOrder->tenant_id, '--cleanup' => true]);

        $this->assertSame('Deleted guest', $cleanGuest->fresh()->first_name);
        $this->assertNull($cleanGuest->fresh()->email);
        $this->assertNull($cleanGuest->fresh()->document_type);
        $this->assertNull($cleanGuest->fresh()->document_number);
        $this->assertSame('shared@example.test', $sharedGuest->fresh()->email);
        $this->assertNotNull($deferredOrder->fresh()->guest_pii_cleanup_deferred_at);
        $this->assertNotNull($cleanOrder->fresh()->pii_scrubbed_at);
        $this->assertNotNull($deferredOrder->fresh()->pii_scrubbed_at);
    }

    public function test_public_contract_routes_fail_closed_with_safe_headers_and_rate_limits(): void
    {
        $response = $this->getJson('/api/v1/direct-booking/properties/rincon-grande', ['X-Correlation-ID' => 'caller-correlation-0001'])
            ->assertServiceUnavailable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Correlation-ID', 'caller-correlation-0001')
            ->assertJsonPath('error.code', 'booking_unavailable');
        $this->assertStringNotContainsString('P3-07A', $response->getContent());

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $result = $this->postJson('/api/v1/direct-booking/properties/rincon-grande/orders', []);
        }
        $result->assertTooManyRequests();
    }

    private function setting(string $propertyId): DirectBookingPropertySetting
    {
        return DirectBookingPropertySetting::query()->create([
            'property_id' => $propertyId,
            'public_slug' => 'rincon-grande',
            'direct_booking_enabled' => true,
            'default_locale' => 'es-AR',
            'supported_locales' => ['es-AR', 'en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD', 'ARS'],
            'accessible_fallback_url' => 'https://example.test/contact',
        ]);
    }

    /** @return array{DirectBookingOrder, BookingQuote} */
    private function orderWithQuote(Property $property): array
    {
        $category = $this->category($property, 'room');
        Resource::factory()->create([
            'property_id' => $property->id,
            'category_id' => $category->id,
            'capacity' => 4,
        ]);
        $plan = RatePlan::query()->create([
            'property_id' => $property->id,
            'name' => 'Direct '.Str::ulid(),
            'currency' => 'USD',
            'state' => 'draft',
            'is_active' => true,
        ]);
        RateRule::query()->create([
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'amount_minor' => 20_000,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);
        $quote = app(BookingQuoteService::class)->create([
            'property_id' => $property->id,
            'rate_plan_id' => $plan->id,
            'resource_category_id' => $category->id,
            'starts_at' => now()->addDays(20)->startOfDay()->toIso8601String(),
            'ends_at' => now()->addDays(22)->startOfDay()->toIso8601String(),
            'adults' => 2,
            'children' => 0,
        ]);
        $setting = DirectBookingPropertySetting::query()->where('property_id', $property->id)->first()
            ?? $this->setting($property->id);
        $order = app(DirectBookingTokenService::class)->issue($setting, 'es-AR', 'USD')['order'];
        $order->forceFill(['booking_quote_id' => $quote->id])->save();

        return [$order, $quote];
    }

    private function publication(
        string $propertyId,
        DirectBookingPublicationKind $kind,
        string $locale,
        ?string $itemId = null,
        bool $withMedia = false,
    ): DirectBookingPublication {
        $publication = DirectBookingPublication::query()->create([
            'property_id' => $propertyId,
            'public_item_id' => $itemId,
            'kind' => $kind,
            'locale' => $locale,
            'version' => 1,
            'state' => DirectBookingPublicationState::Published,
            'title' => ucfirst(str_replace('_', ' ', $kind->value)),
            'summary' => 'Safe public summary.',
            'body' => 'A real published policy or content body.',
            'effective_at' => now()->subMinute(),
            'published_at' => now(),
        ]);
        if ($withMedia) {
            DirectBookingPublicMedia::query()->create([
                'publication_id' => $publication->id,
                'media_reference' => 'public-media://direct-booking/example.webp',
                'mime_type' => 'image/webp',
                'alt_text' => 'Accessible description',
                'width' => 1200,
                'height' => 800,
            ]);
        }

        return $publication->fresh('media');
    }
}
