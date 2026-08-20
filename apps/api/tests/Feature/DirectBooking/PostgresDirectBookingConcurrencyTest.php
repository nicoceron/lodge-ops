<?php

namespace Tests\Feature\DirectBooking;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use App\Models\DirectBookingPropertySetting;
use App\Models\Membership;
use App\Models\Tenant;
use App\Services\DirectBooking\DirectBookingStateMachine;
use App\Services\DirectBooking\DirectBookingTokenService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
        $order = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];

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
    }

    public function test_concurrent_same_retry_identity_records_once_and_replays(): void
    {
        $this->requirePostgres();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $setting = DirectBookingPropertySetting::query()->create([
            'property_id' => $property->id, 'public_slug' => 'retry-race', 'default_locale' => 'en',
            'supported_locales' => ['en'], 'default_currency' => 'USD', 'supported_currencies' => ['USD'],
        ]);
        $order = app(DirectBookingTokenService::class)->issue($setting, 'en', 'USD')['order'];
        $operation = fn (): string => app(DirectBookingStateMachine::class)->transition(
            $order, DirectBookingOrderState::Quoted, DirectBookingTransitionAuthority::Pricing, 1, 'same-retry-command-0001',
        )->event->id;
        $results = $this->concurrently([$operation, $operation], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);

        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertCount(1, collect($results)->pluck('result')->unique());
        $this->assertDatabaseCount('direct_booking_order_events', 1);
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
}
