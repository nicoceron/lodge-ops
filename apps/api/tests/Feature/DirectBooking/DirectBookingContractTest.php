<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Exceptions\DirectBookingContractException;
use App\Models\DirectBookingPaymentCapability;
use App\Models\DirectBookingPropertySetting;
use App\Models\DirectBookingPublication;
use App\Models\DirectBookingPublicItem;
use App\Models\DirectBookingPublicMedia;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Services\DirectBooking\CloudflareTurnstileVerifier;
use App\Services\DirectBooking\DirectBookingConsentRecorder;
use App\Services\DirectBooking\DirectBookingLaunchReadinessEvaluator;
use App\Services\DirectBooking\DirectBookingSafeProjection;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame(120, (int) $order->created_at->diffInMinutes($order->expires_at));
        $this->assertSame(64, strlen($issued['token']));
        $this->assertSame(hash('sha256', $issued['token']), $order->getRawOriginal('token_hash'));
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
            app(DirectBookingTokenService::class)->resolve($rotated['token'], $property->id);
            $this->fail('A revoked token must fail generically.');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }

        $fresh = app(DirectBookingTokenService::class)->issue($setting, 'es-AR', 'USD');
        $fresh['order']->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->expectException(AuthenticationException::class);
        app(DirectBookingTokenService::class)->resolve($fresh['token'], $property->id);
    }

    public function test_state_machine_freezes_authority_replay_version_hold_and_late_payment_rules(): void
    {
        [, $property] = $this->tenantEnvironment();
        $order = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD')['order'];
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

        $held = $states->transition($order, DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory, 2, 'hold-command-00001');
        $this->assertSame(30, (int) $held->order->held_at->diffInMinutes($held->order->expires_at));
        $pending = $states->transition($order, DirectBookingOrderState::PaymentPending, DirectBookingTransitionAuthority::PaymentOrchestrator, 3, 'checkout-command-01');
        $extended = $states->transition($order, DirectBookingOrderState::PaymentPending, DirectBookingTransitionAuthority::PaymentOrchestrator, 4, 'extend-command-0001', ['hold_extension_minutes' => 15]);
        $this->assertSame(45, (int) $extended->order->held_at->diffInMinutes($extended->order->expires_at));
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
        $this->assertSame(DirectBookingOrderState::PaymentPending, $pending->event->to_state);
    }

    public function test_manual_payment_branch_requires_evidence_scanner_finance_and_reservation_authorities(): void
    {
        [, $property] = $this->tenantEnvironment();
        $order = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD')['order'];
        $states = app(DirectBookingStateMachine::class);
        $steps = [
            [DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing],
            [DirectBookingOrderState::Held, DirectBookingTransitionAuthority::Inventory],
            [DirectBookingOrderState::AwaitingManualPayment, DirectBookingTransitionAuthority::PaymentOrchestrator],
            [DirectBookingOrderState::EvidencePending, DirectBookingTransitionAuthority::GuestEvidence],
            [DirectBookingOrderState::FinanceReview, DirectBookingTransitionAuthority::EvidenceScanner],
            [DirectBookingOrderState::Confirmed, DirectBookingTransitionAuthority::Finance],
        ];
        foreach ($steps as $index => [$state, $authority]) {
            $result = $states->transition($order, $state, $authority, $index + 1, 'manual-flow-'.str_pad((string) $index, 8, '0', STR_PAD_LEFT));
            $order = $result->order;
        }
        $this->assertSame(DirectBookingOrderState::Confirmed, $order->state);
        $this->assertDatabaseCount('direct_booking_order_events', 6);
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
        $item = DirectBookingPublicItem::query()->create([
            'property_id' => $property->id, 'kind' => 'category', 'resource_category_id' => $category->id, 'is_enabled' => true,
        ]);
        $this->publication($property->id, DirectBookingPublicationKind::Property, 'es-AR', withMedia: true);
        $this->publication($property->id, DirectBookingPublicationKind::Category, 'es-AR', $item->id, withMedia: true);
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
        ]);
        $json = json_encode([$projection, $availability], JSON_THROW_ON_ERROR);
        foreach ([$category->id, 'internal-room-id', 'Room 7', 'available_units', 'maximum_occupancy', 'staff_note', 'provider_metadata', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame([['key' => $item->public_key, 'bookable' => true]], $availability);
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
                'success' => true, 'hostname' => 'book.example.test', 'action' => 'direct_booking_hold', 'error-codes' => [],
            ]),
        ]);
        $result = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', '203.0.113.8', 'direct_booking_hold', 'turnstile-command-0001');
        $this->assertTrue($result->valid);
        Http::assertSent(fn ($request): bool => $request['secret'] === 'secret-value'
            && $request['response'] === 'single-use-token'
            && $request['idempotency_key'] === 'turnstile-command-0001');
        $wrongAction = app(CloudflareTurnstileVerifier::class)->verify('single-use-token', null, 'direct_booking_begin', 'turnstile-command-0002');
        $this->assertFalse($wrongAction->valid);
    }

    public function test_launch_readiness_fails_closed_until_content_commercial_payment_and_accessibility_inputs_exist(): void
    {
        [, $property] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id,
            'public_slug' => 'readiness-property',
            'direct_booking_enabled' => true,
            'default_locale' => 'en',
            'supported_locales' => ['en'],
            'default_currency' => 'USD',
            'supported_currencies' => ['USD'],
            'accessible_fallback_url' => 'https://example.test/contact',
        ]);
        $blocked = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting);
        $this->assertFalse($blocked->ready);
        $this->assertContains('bookable_projection_missing', $blocked->blockingReasons);
        $this->assertContains('commercial_rules_missing:USD', $blocked->blockingReasons);
        $this->assertContains('payment_capability_missing:USD', $blocked->blockingReasons);

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
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $property->id, 'currency' => 'USD', 'method' => 'manual_bank_transfer',
            'is_enabled' => true, 'instructions_publication_id' => $instructions->id,
        ]);

        $ready = app(DirectBookingLaunchReadinessEvaluator::class)->evaluate($setting->fresh());
        $this->assertTrue($ready->ready, implode(', ', $ready->blockingReasons));
        $this->assertSame([], $ready->blockingReasons);
    }

    public function test_singleton_maintenance_expires_sessions_and_scrubs_pii_without_deleting_ledger(): void
    {
        [, $property] = $this->tenantEnvironment();
        $issued = app(DirectBookingTokenService::class)->issue($this->setting($property->id), 'es-AR', 'USD');
        $issued['order']->forceFill([
            'expires_at' => now()->subMinute(),
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
        $this->assertNotNull($scrubbed->revoked_at);
        $this->assertDatabaseCount('direct_booking_orders', 1);
        $this->assertDatabaseCount('direct_booking_order_events', 2);
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
