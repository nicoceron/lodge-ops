<?php

namespace Tests\Feature\DirectBooking;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderPayment;
use App\Enums\DirectBookingErrorCode;
use App\Enums\DirectBookingPublicationKind;
use App\Enums\DirectBookingPublicationState;
use App\Enums\ProviderEventState;
use App\Enums\ReservationStatus;
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
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Program;
use App\Models\ProviderEvent;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Reservation;
use App\Models\Resource;
use App\Services\DirectBooking\DirectBookingLaunchReadinessEvaluator;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Services\Payments\ExecuteProviderRefund;
use App\Services\Payments\ProcessProviderEvent;
use App\Services\RequestRefund;
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

        $propertyResponse = $this->getJson($base.'?locale=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonMissingPath('data.bookables.0.resource_category_id')
            ->assertJsonPath('data.optional_services.0.name', 'Airport transfer')
            ->assertJsonPath('data.optional_services.0.pricing.unit_amount.currency', 'USD');
        $optionalServiceKey = $propertyResponse->json('data.optional_services.0.key');

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
            'optional_service_keys' => [$optionalServiceKey],
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonFragment(['type' => 'service', 'description' => 'Public riding program'])
            ->assertJsonFragment(['type' => 'included_service', 'description' => 'Breakfast · included'])
            ->assertJsonFragment(['type' => 'optional_service', 'description' => 'Airport transfer'])
            ->assertJsonPath('data.optional_services.0.key', $optionalServiceKey);
        $consents = collect($quoted->json('data.policies'))->mapWithKeys(fn (array $policy): array => [
            $policy['kind'] => ['version' => $policy['version'], 'checksum' => $policy['checksum'], 'accepted' => $policy['kind'] !== 'marketing_consent'],
        ])->all();

        $rejectedConsents = $consents;
        $rejectedConsents['terms']['accepted'] = false;
        $this->postJson($base."/orders/{$reference}/hold", [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'email' => 'public@example.test'],
            'companions' => [['first_name' => 'Travel', 'guest_type' => 'child']],
            'consents' => $rejectedConsents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('deposits', 0);

        $holdBody = [
            'expected_state_version' => 2,
            'guest' => ['first_name' => 'Public', 'last_name' => 'Guest', 'email' => 'public@example.test', 'phone' => '+12025550123'],
            'companions' => [['first_name' => 'Travel', 'last_name' => 'Companion', 'guest_type' => 'adult']],
            'consents' => $consents,
            'turnstile_token' => 'test-token',
            'turnstile_action' => 'direct_booking_hold',
        ];
        $holdKey = (string) Str::uuid();
        config(['direct-booking.testing.fail_command_completion' => $holdKey]);
        $this->postJson($base."/orders/{$reference}/hold", $holdBody, $auth + ['Idempotency-Key' => $holdKey])
            ->assertServiceUnavailable();
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('deposits', 0);
        $this->assertDatabaseCount('payment_requests', 0);
        $this->assertDatabaseCount('guests', 0);
        config(['direct-booking.testing.fail_command_completion' => null]);
        $held = $this->postJson($base."/orders/{$reference}/hold", $holdBody, $auth + ['Idempotency-Key' => $holdKey])
            ->assertOk()->assertJsonPath('data.state', 'held');
        app(TenantContext::class)->set($tenant, $membership);
        $heldOrder = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $this->assertNotNull($heldOrder->payment_request_id);
        $this->assertSame(1, $heldOrder->reservation->deposits()->count());
        $this->assertTrue($heldOrder->hold_expires_at->equalTo($heldOrder->reservation->hold_expires_at));
        $this->assertTrue($heldOrder->hold_expires_at->between(
            now()->addMinutes($setting->initial_hold_minutes)->subMinute(),
            now()->addMinutes($setting->initial_hold_minutes)->addMinute(),
        ));

        $checkout = $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'manual_bank_transfer',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_manual_payment')
            ->assertJsonPath('data.manual_payment_instructions.locale', 'en')
            ->assertJsonPath('data.manual_payment_instructions.currency', 'USD')
            ->assertJsonPath('data.manual_payment_instructions.title', 'Bank transfer instructions')
            ->assertJsonPath('data.manual_payment_instructions.body', 'Published content.')
            ->assertJsonPath('data.manual_payment_instructions.version', 1);

        $evidence = $this->post($base."/orders/{$reference}/manual-payment-evidence", [
            'expected_state_version' => $checkout->json('data.state_version'),
            'evidence' => UploadedFile::fake()->image('receipt.png', 20, 20),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.state', 'evidence_pending');

        app(TenantContext::class)->set($tenant, $membership);
        $paymentEvidence = GuestPaymentEvidence::query()->sole();
        $reservation = $paymentEvidence->reservation;
        $this->assertInstanceOf(Reservation::class, $reservation);
        $deposit = $reservation->deposits()->where('schedule_type', 'deposit_50')->firstOrFail();
        config(['direct-booking.testing.fail_confirmation_provisioning' => true]);
        $this->assertThrows(
            fn () => app(ReviewPaymentEvidence::class)->approve($paymentEvidence, $deposit->id, $actor->id, 'Verified test transfer'),
            \RuntimeException::class,
        );
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('service_occurrences', 0);
        $this->assertDatabaseCount('document_generation_requests', 0);
        $this->assertSame('finance_review', DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail()->state->value);
        $this->assertSame(ReservationStatus::Hold, $reservation->fresh()->status);
        config(['direct-booking.testing.fail_confirmation_provisioning' => false]);
        app(ReviewPaymentEvidence::class)->approve($paymentEvidence, $deposit->id, $actor->id, 'Verified test transfer');

        $status = $this->getJson($base."/orders/{$reference}", $auth)
            ->assertOk()->assertJsonPath('data.state', 'confirmed');
        $ready = $this->getJson($base."/orders/{$reference}/confirmation", $auth)
            ->assertOk()
            ->assertJsonPath('data.state', 'confirmed')
            ->assertJsonPath('data.links.guest_portal.status', 'invitation_required')
            ->assertJsonPath('data.links.guest_portal.entry_path', '/guest/stay')
            ->assertJsonCount(2, 'data.documents');

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 6);
        $this->assertDatabaseCount('guests', 2);
        $this->assertDatabaseCount('reservation_guests', 2);
        $this->assertDatabaseCount('allocations', 3);
        $this->assertDatabaseCount('service_occurrences', 1);
        $this->assertDatabaseCount('document_generation_requests', 2);
        $this->assertDatabaseHas('payment_requests', ['payment_id' => Payment::query()->value('id'), 'state' => 'paid']);
        $this->assertDatabaseCount('direct_booking_command_responses', 5);
        $this->assertSame('view_confirmation', $status->json('data.actions.0'));
        $this->assertDatabaseMissing('direct_booking_command_responses', ['response_body_encrypted' => $begun->getContent()]);

        $receipt = collect($ready->json('data.documents'))->firstWhere('kind', 'payment_receipt');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $receipt['document_reference']);
        $this->get($receipt['download_path'], $auth)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get($base."/orders/{$reference}/confirmation/documents/".str_repeat('f', 64), $auth)
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');

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
        $checkoutBody = [
            'expected_state_version' => $held->json('data.state_version'),
            'method' => 'hosted_checkout',
        ];
        $checkoutKey = (string) Str::uuid();
        $fake->failAfterCheckoutCreationOnce = true;
        $this->postJson($base."/orders/{$reference}/checkout", $checkoutBody, $auth + ['Idempotency-Key' => $checkoutKey])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'booking_unavailable');
        $checkout = $this->postJson($base."/orders/{$reference}/checkout", $checkoutBody, $auth + ['Idempotency-Key' => $checkoutKey])
            ->assertOk()->assertJsonPath('data.state', 'payment_pending');
        $checkoutReplay = $this->postJson($base."/orders/{$reference}/checkout", $checkoutBody, $auth + ['Idempotency-Key' => $checkoutKey])
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($checkout->getContent(), $checkoutReplay->getContent());
        $this->assertCount(1, $fake->checkouts);

        // A browser return/status poll is intentionally non-authoritative.
        $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'payment_pending');
        app(TenantContext::class)->set($tenant, $membership);
        /** @var PaymentAttempt $attempt */
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
        /** @var PaymentAttempt $attempt */
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

    public function test_replay_is_session_bound_and_existing_status_survives_launch_disable(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting] = $this->launchReadyProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $key = (string) Str::uuid();
        $body = [
            'locale' => 'en', 'currency' => 'USD',
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_begin',
        ];
        $begun = $this->postJson($base.'/orders', $body, ['Idempotency-Key' => $key])->assertCreated();
        app(TenantContext::class)->set($tenant, $membership);
        $order = DirectBookingOrder::query()->where('public_reference', $begun->json('data.order_reference'))->firstOrFail();
        $rotated = app(DirectBookingTokenService::class)->rotate($order);

        $this->postJson($base.'/orders', $body, ['Idempotency-Key' => $key])
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        app(TenantContext::class)->set($tenant, $membership);
        $setting->forceFill(['direct_booking_enabled' => false])->save();
        $auth = ['Authorization' => 'Bearer '.$rotated['token']];
        $this->getJson($base.'/orders/'.$order->public_reference, $auth)
            ->assertOk()->assertJsonPath('data.state', 'started');
        $this->getJson($base.'?locale=en')->assertServiceUnavailable()->assertJsonPath('error.code', 'booking_unavailable');

        app(TenantContext::class)->set($tenant, $membership);
        app(DirectBookingTokenService::class)->revoke($rotated['order']);
        $this->getJson($base.'/orders/'.$order->public_reference, $auth)
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_local_command_result_crash_rolls_back_domain_and_same_key_recovers(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting] = $this->launchReadyProperty($property);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $key = (string) Str::uuid();
        $body = [
            'locale' => 'en', 'currency' => 'USD',
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_begin',
        ];
        config(['direct-booking.testing.fail_command_completion' => $key]);
        $this->postJson($base.'/orders', $body, ['Idempotency-Key' => $key])
            ->assertServiceUnavailable()->assertJsonPath('error.code', 'booking_unavailable');
        $this->assertDatabaseCount('direct_booking_orders', 0);
        $this->assertDatabaseCount('direct_booking_command_responses', 0);

        config(['direct-booking.testing.fail_command_completion' => null]);
        $created = $this->postJson($base.'/orders', $body, ['Idempotency-Key' => $key])->assertCreated();
        $replayed = $this->postJson($base.'/orders', $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($created->getContent(), $replayed->getContent());
        $this->assertDatabaseCount('direct_booking_orders', 1);
        $this->assertDatabaseCount('direct_booking_command_responses', 1);
    }

    public function test_superseded_late_approval_is_refundable_truth_and_never_confirms_inventory(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        [$setting, $categoryItem] = $this->launchReadyProperty($property);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $connection = $this->hostedConnection($property->id);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $stay = [
            'arrival_date' => now()->addDays(40)->toDateString(),
            'departure_date' => now()->addDays(42)->toDateString(),
            'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            'category_key' => $categoryItem->public_key,
            'currency' => 'USD', 'locale' => 'en',
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
            'guest' => ['first_name' => 'Late', 'email' => 'late@example.test'],
            'consents' => $consents,
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_hold',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->postJson($base."/orders/{$reference}/checkout", [
            'expected_state_version' => $held->json('data.state_version'), 'method' => 'hosted_checkout',
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        app(TenantContext::class)->set($tenant, $membership);
        /** @var PaymentAttempt $oldAttempt */
        $oldAttempt = $connection->paymentAttempts()->sole();
        $fake->payments['late-rejected'] = new ProviderPayment(
            'late-rejected', $oldAttempt->external_reference, 'rejected', 'rejected',
            $oldAttempt->charge_amount_minor, $oldAttempt->charge_currency, 'seller-late',
        );
        app(ProcessProviderEvent::class)->handle($this->providerEvent($connection, 'late-rejected', 'late-delivery-rejected'));
        $failed = $this->getJson($base."/orders/{$reference}", $auth)->assertJsonPath('data.state', 'payment_failed');
        $this->postJson($base."/orders/{$reference}/payments/retry", [
            'expected_state_version' => $failed->json('data.state_version'),
        ], $auth + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        app(TenantContext::class)->set($tenant, $membership);
        $fake->payments['late-approved'] = new ProviderPayment(
            'late-approved', $oldAttempt->external_reference, 'approved', 'accredited',
            $oldAttempt->charge_amount_minor + 123, $oldAttempt->charge_currency, 'seller-late',
        );
        app(ProcessProviderEvent::class)->handle($this->providerEvent($connection, 'late-approved', 'late-delivery-approved'));
        $order = DirectBookingOrder::query()->where('public_reference', $reference)->firstOrFail();
        $payment = Payment::query()->sole();
        $this->assertSame('paid_needs_review', $order->state->value);
        $this->assertSame(ReservationStatus::Hold, $order->reservation->status);
        $this->assertNull($order->reservation->confirmed_at);
        $this->assertTrue((bool) data_get($payment->metadata, 'unapplied_direct_booking_funds'));
        $this->assertDatabaseCount('operational_tasks', 1);

        $refundRequest = app(RequestRefund::class)->handle(
            $order->reservation,
            $payment,
            $payment->amount_minor,
            'Late superseded direct-booking approval',
            null,
        );
        app(ExecuteProviderRefund::class)->handle($refundRequest, null);
        $this->assertSame('refunded', $order->fresh()->state->value);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('provider_refunds', ['payment_id' => $payment->id, 'state' => 'succeeded']);
    }

    public function test_rate_limit_and_all_error_catalog_facts_match_the_frozen_contract(): void
    {
        [, $property] = $this->tenantEnvironment();
        [$setting] = $this->launchReadyProperty($property);
        config(['direct-booking.rate_limits.mutation_per_minute' => 1]);
        $base = '/api/v1/direct-booking/properties/'.$setting->public_slug;
        $body = [
            'locale' => 'en', 'currency' => 'USD',
            'turnstile_token' => 'test-token', 'turnstile_action' => 'direct_booking_begin',
        ];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->postJson($base.'/orders', $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $limited = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->postJson($base.'/orders', $body, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertTooManyRequests()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id', 'retryable']]);
        $this->assertSame('rate_limited', $limited->json('error.code'));
        $this->assertTrue($limited->json('error.retryable'));

        $catalog = [
            'validation_error' => [422, false], 'unavailable' => [409, true], 'quote_stale' => [409, true],
            'hold_expired' => [410, true], 'conflict' => [409, true], 'idempotency_conflict' => [409, false],
            'rate_limited' => [429, true], 'bot_rejected' => [403, true], 'payment_pending' => [409, true],
            'payment_failed' => [409, true], 'paid_needs_review' => [409, false], 'not_found' => [404, false],
            'booking_unavailable' => [503, true],
        ];
        foreach (DirectBookingErrorCode::cases() as $code) {
            $this->assertSame($catalog[$code->value][0], $code->httpStatus(), $code->value.' status');
            $this->assertSame($catalog[$code->value][1], $code->retryable(), $code->value.' retryability');
            $this->assertNotEmpty($code->publicMessage(), $code->value.' message');
        }
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
        $transfer = CatalogItem::query()->create([
            'sku' => 'PUBLIC-TRANSFER',
            'name' => 'Airport transfer',
            'type' => 'service',
            'currency' => 'USD',
            'price_minor' => 4_500,
        ]);
        $plan->services()->create([
            'catalog_item_id' => $transfer->id,
            'selection_type' => 'optional',
            'quantity_basis' => 'per_stay',
            'default_quantity' => 1,
            'maximum_quantity' => 1,
            'is_active' => true,
        ]);
        DB::table('rate_plans')->where('id', $plan->id)->update(['state' => 'published', 'published_at' => now()]);

        return [$setting, $item];
    }

    private function hostedConnection(string $propertyId): IntegrationConnection
    {
        $connection = IntegrationConnection::query()->create([
            'property_id' => $propertyId,
            'name' => 'Late direct booking provider',
            'type' => 'payment', 'provider' => 'mercado_pago', 'product' => 'checkout_pro',
            'external_account_id' => 'seller-late', 'environment' => 'sandbox',
            'status' => 'connected', 'is_enabled' => true, 'capabilities' => ['payment.hosted_checkout'],
            'configuration' => [
                'provider_account' => 'seller-late', 'charge_currency' => 'USD',
                'return_url_base' => 'https://book.example.test', 'webhook_key' => str_repeat('l', 48),
            ],
            'secret_reference' => 'env:DIRECT_BOOKING_TEST_TOKEN',
        ]);
        $connection->connectionCapabilities()->create([
            'capability' => 'payment.hosted_checkout', 'direction' => 'outbound', 'state' => 'enabled', 'configuration_version' => 1,
        ]);
        DirectBookingPaymentCapability::query()->create([
            'property_id' => $propertyId, 'currency' => 'USD', 'method' => 'hosted_checkout',
            'is_enabled' => true, 'provider_connection_id' => $connection->id,
        ]);

        return $connection;
    }

    private function providerEvent(IntegrationConnection $connection, string $resourceId, string $deliveryId): ProviderEvent
    {
        return ProviderEvent::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago', 'environment' => 'sandbox',
            'provider_account' => $connection->external_account_id,
            'delivery_id' => $deliveryId, 'topic' => 'payment', 'event_type' => 'payment',
            'action' => 'payment.updated', 'resource_id' => $resourceId,
            'signature_valid' => true, 'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', $deliveryId),
        ]);
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
