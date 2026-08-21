<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\BookingQuoteStatus;
use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Models\BookingQuote;
use App\Models\DirectBookingOrder;
use App\Models\DirectBookingPropertySetting;
use App\Models\Membership;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RateRule;
use App\Models\Resource;
use App\Models\Tenant;
use App\Services\BookingQuoteService;
use App\Services\CommitBookingQuote;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresDirectBookingConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

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
