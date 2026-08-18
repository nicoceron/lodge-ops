<?php

namespace Tests\Feature;

use App\Enums\FolioLineType;
use App\Enums\PaymentStatus;
use App\Http\Middleware\EnsureIdempotentCommand;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompleteRefund;
use App\Services\FolioService;
use App\Services\PaymentService;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;
use Throwable;

class PostgresFinancialConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_concurrent_refund_request_and_legacy_reversal_cannot_both_mutate_money(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $payment, $user, $membership] = $this->refundablePayment();

        $results = $this->concurrently([
            function () use ($reservation, $payment, $user): string {
                $change = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Concurrent partial refund', $user->id);

                return 'refund:'.$change->id;
            },
            function () use ($payment, $user): string {
                $result = app(PaymentService::class)->reverse($payment, 'Concurrent legacy reversal', $user->id);

                return 'reversal:'.$result->status->value;
            },
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $payment = $payment->fresh();
        $refundRequests = $reservation->changes()->where('type', 'refund_requested')->count();
        $reversalLines = $reservation->folioLines()->where('payment_id', $payment->id)->where('type', 'refund')->count();
        $successes = collect($results)->where('ok', true)->count();

        $this->assertSame(1, $successes, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertTrue(
            ($payment->status === PaymentStatus::Succeeded && $refundRequests === 1 && $reversalLines === 0)
            || ($payment->status === PaymentStatus::Reversed && $refundRequests === 0 && $reversalLines === 1),
            json_encode($results, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(0, $reservation->changes()->where('type', 'refund_completed')->count());
    }

    public function test_concurrent_refund_completions_post_one_append_only_effect(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $payment, $user, $membership] = $this->refundablePayment();
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Concurrent completion', $user->id);

        $results = $this->concurrently([
            fn (): string => 'complete:'.app(CompleteRefund::class)->handle($request, 'completion-a', $user->id)->id,
            fn (): string => 'complete:'.app(CompleteRefund::class)->handle($request, 'completion-b', $user->id)->id,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
        $this->assertSame(-5_000, app(FolioService::class)->summary($reservation)['balance_minor']);
        $completedId = $request->events()->where('type', 'refund_completed')->value('id');
        $this->assertSame([$completedId], collect($results)->pluck('result')->map(fn (string $value): string => str($value)->after('complete:')->toString())->unique()->values()->all());
    }

    public function test_postgres_rejects_invalid_or_unattributed_provider_payment_origins(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL check constraints are exercised by the PostgreSQL gate.');
        }
        [, $property] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'currency' => 'USD']);

        foreach ([
            ['origin' => 'unknown', 'provider' => null, 'provider_reference' => null],
            ['origin' => 'provider', 'provider' => null, 'provider_reference' => null],
        ] as $invalid) {
            try {
                DB::table('payments')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $reservation->tenant_id,
                    'reservation_id' => $reservation->id,
                    'status' => 'pending',
                    'method' => 'card',
                    'origin' => $invalid['origin'],
                    'provider' => $invalid['provider'],
                    'provider_reference' => $invalid['provider_reference'],
                    'currency' => 'USD',
                    'amount_minor' => 1000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('The PostgreSQL payment-origin constraint should reject this row.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_concurrent_identical_idempotency_claims_execute_once_then_replay(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, , $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create(['property_id' => $property->id]);
        $key = 'postgres-idempotency-race-0001';

        $results = $this->concurrently([
            fn (): string => $this->runIdempotentProbe($reservation->id, $key),
            fn (): string => $this->runIdempotentProbe($reservation->id, $key),
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->filter(
            fn (array $result): bool => str($result['error'] ?? '')->contains('already in progress'),
        )->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('idempotency_keys', ['key' => $key, 'status_code' => 201]);
        $this->assertSame('201:replayed:1', $this->runIdempotentProbe($reservation->id, $key));
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    /** @return array{Tenant, Reservation, Payment, User, Membership} */
    private function refundablePayment(): array
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
        ]);
        app(FolioService::class)->append($reservation, FolioLineType::Charge, 'Stay', 1000, 10_000, $user->id, includedInBookedTotal: true);
        $payment = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 20_000,
        ], $user->id, true);

        return [$tenant, $reservation, $payment, $user, $membership];
    }

    /** @param array<int, callable(): string> $operations @return array<int, array{ok: bool, result?: string, error?: string}> */
    private function concurrently(array $operations, Tenant $tenant, Membership $membership): array
    {
        $directory = sys_get_temp_dir().'/inn-pg-concurrency-'.Str::random(12);
        mkdir($directory, 0700, true);
        $barrier = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($barrier === false) {
            $this->fail('Unable to create the concurrency barrier.');
        }
        $children = [];

        foreach ($operations as $index => $operation) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a PostgreSQL concurrency worker.');
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
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "Concurrency worker {$pid} failed.");
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

    private function runIdempotentProbe(string $reservationId, string $key): string
    {
        $request = Request::create(
            '/testing/idempotency-probe',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reservation_id' => $reservationId], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Idempotency-Key', $key);
        $route = new Route('POST', 'testing/idempotency-probe', fn () => null);
        $request->setRouteResolver(fn (): Route => $route);

        $response = app(EnsureIdempotentCommand::class)->handle($request, function () use ($reservationId) {
            $affected = DB::table('reservations')->where('id', $reservationId)->increment('revision');
            usleep(250_000);

            return response()->json(['data' => ['reservation_id' => $reservationId, 'affected' => $affected]], 201);
        });

        return $response->getStatusCode()
            .':'.($response->headers->get('Idempotency-Replayed') === 'true' ? 'replayed' : 'executed')
            .':'.data_get(json_decode((string) $response->getContent(), true), 'data.affected');
    }

    private function requirePostgresConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL row-lock concurrency is exercised by the PostgreSQL gate.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The PostgreSQL concurrency gate requires pcntl.');
        }
    }
}
