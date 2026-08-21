<?php

namespace Tests\Feature;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\FrontDeskPaymentInput;
use App\Data\Payments\ProviderDispute as ProviderDisputeData;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderRefund as ProviderRefundData;
use App\Enums\CashShiftState;
use App\Enums\DepositStatus;
use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use App\Enums\FolioLineType;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEvidenceStatus;
use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentStatus;
use App\Enums\ProviderEventState;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use App\Enums\ReservationStatus;
use App\Http\Middleware\EnsureIdempotentCommand;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\Deposit;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\IntegrationConnection;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\ProviderEvent;
use App\Models\ProviderRefund;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CompleteRefund;
use App\Services\FolioService;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Payments\CloseCashShift;
use App\Services\Payments\CompleteManualExternalRefund;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\Payments\ExecuteProviderRefund;
use App\Services\Payments\IssuePaymentRequest;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\ProcessProviderEvent;
use App\Services\Payments\RecordFrontDeskPayment;
use App\Services\Payments\RecordSettlementRevision;
use App\Services\Payments\RecoverProviderRefund;
use App\Services\Payments\RequestManualExternalRefund;
use App\Services\PaymentService;
use App\Services\RequestRefund;
use App\Services\ReviewPaymentEvidence;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;
use Throwable;

class PostgresFinancialConcurrencyTest extends TestCase
{
    use CreatesTenant, DatabaseMigrations;

    public function test_concurrent_external_terminal_identity_records_one_payment_and_one_review_exception(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $firstReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'subtotal_minor' => 20_000,
            'tax_minor' => 0,
            'total_minor' => 20_000,
        ]);
        $secondReservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'subtotal_minor' => 20_000,
            'tax_minor' => 0,
            'total_minor' => 20_000,
        ]);
        $record = fn (Reservation $reservation, string $key): string => app(RecordFrontDeskPayment::class)->handle(
            $user,
            $this->terminalTenderInput($reservation, $key),
        )->state;

        $results = $this->concurrently([
            fn (): string => $record($firstReservation, 'pg-terminal-race-command-a'),
            fn (): string => $record($secondReservation, 'pg-terminal-race-command-b'),
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertEqualsCanonicalizing(['duplicate_review', 'posted'], collect($results)->pluck('result')->all());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseCount('payment_tender_details', 2);
    }

    public function test_concurrent_cash_shift_opens_leave_exactly_one_open_shift(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();

        $results = $this->concurrently([
            fn (): string => app(OpenCashShift::class)->handle($user, $property->id, 'COP', 10_000, 'pg-open-shift-race-a')->id,
            fn (): string => app(OpenCashShift::class)->handle($user, $property->id, 'COP', 10_000, 'pg-open-shift-race-b')->id,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, CashShift::query()->where('state', CashShiftState::Open)->count());
        $this->assertDatabaseCount('cash_shift_movements', 1);
    }

    public function test_evidence_approval_racing_direct_tender_obeys_global_lock_order_and_posts_once(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'currency' => 'USD',
            'subtotal_minor' => 5_000,
            'tax_minor' => 0,
            'total_minor' => 5_000,
        ]);
        $deposit = Deposit::query()->create([
            'reservation_id' => $reservation->id,
            'status' => DepositStatus::Due,
            'currency' => 'USD',
            'amount_minor' => 5_000,
        ]);
        $evidence = GuestPaymentEvidence::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'file_name' => 'race-receipt.pdf',
            'content_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'race-receipt'),
            'storage_path' => 'guest-payment-evidence/race-receipt.pdf',
            'status' => PaymentEvidenceStatus::Pending,
            'amount_minor' => 5_000,
            'currency' => 'USD',
            'scan_status' => 'accepted',
            'submitted_at' => now(),
        ]);

        $results = $this->concurrently([
            fn (): string => app(ReviewPaymentEvidence::class)->approve($evidence, $deposit->id, $user->id, 'Concurrent approval')->id,
            fn (): string => app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
                $reservation->id,
                PaymentChannel::BankTransfer,
                5_000,
                'pg-evidence-tender-race',
                depositId: $deposit->id,
                transactionReference: 'direct-race-transfer',
            ))->id,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertSame(DepositStatus::Paid, $deposit->fresh()->status);
    }

    public function test_cash_payment_racing_shift_close_never_posts_without_a_drawer_movement(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'subtotal_minor' => 5_000,
            'tax_minor' => 0,
            'total_minor' => 5_000,
        ]);
        $shift = app(OpenCashShift::class)->handle($user, $property->id, 'COP', 1_000, 'pg-payment-close-open');

        $results = $this->concurrently([
            fn (): string => 'payment:'.app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
                $reservation->id,
                PaymentChannel::Cash,
                5_000,
                'pg-payment-close-record',
            ))->id,
            fn (): string => 'close:'.app(CloseCashShift::class)->handle($user, $shift, 1_000, 'Concurrent count', 'pg-payment-close-shift')->state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $payments = Payment::query()->where('reservation_id', $reservation->id)->count();
        $paymentMovements = CashShiftMovement::query()->where('cash_shift_id', $shift->id)->where('type', 'payment')->count();
        $this->assertSame(1, collect($results)->filter(fn (array $result): bool => ($result['ok'] ?? false) && str($result['result'])->startsWith('close:'))->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame($payments, $paymentMovements, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertContains($payments, [0, 1]);
        $this->assertNotSame(CashShiftState::Open, $shift->fresh()->state);
    }

    public function test_cash_refund_racing_shift_close_never_completes_without_negative_drawer_movement(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
        ]);
        $shift = app(OpenCashShift::class)->handle($user, $property->id, 'COP', 0, 'pg-refund-close-open');
        $detail = app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
            $reservation->id,
            PaymentChannel::Cash,
            10_000,
            'pg-refund-close-payment',
        ));
        $reservation->update(['subtotal_minor' => 7_000, 'total_minor' => 7_000]);
        $refund = app(RequestManualExternalRefund::class)->handle($user, $detail->payment, 3_000, 'Cash overpayment', 'pg-refund-close-request');

        $results = $this->concurrently([
            fn (): string => 'refund:'.app(CompleteManualExternalRefund::class)->handle(
                $user,
                $refund,
                'drawer-slip-race',
                'pg-refund-close-complete',
                null,
                $shift,
            )->id,
            fn (): string => 'close:'.app(CloseCashShift::class)->handle($user, $shift, 7_000, 'Concurrent close', 'pg-refund-close-shift')->state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $completedRefunds = $reservation->changes()->where('type', 'refund_completed')->count();
        $refundMovements = CashShiftMovement::query()->where('cash_shift_id', $shift->id)->where('type', 'refund')->where('amount_minor', -3_000)->count();
        $this->assertSame(1, collect($results)->filter(fn (array $result): bool => ($result['ok'] ?? false) && str($result['result'])->startsWith('close:'))->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame($completedRefunds, $refundMovements, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertContains($completedRefunds, [0, 1]);
        $this->assertNotSame(CashShiftState::Open, $shift->fresh()->state);
    }

    public function test_postgres_allows_only_one_reusable_attempt_per_payment_request_under_race(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => 50_000,
            'tax_minor' => 0,
            'total_minor' => 50_000,
        ]);
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, $user->id)->request;
        $connection = IntegrationConnection::query()->create([
            'name' => 'race-gateway', 'type' => 'payment',
            'configuration' => ['provider' => 'mercado_pago'], 'secret_reference' => 'env:RACE_GATEWAY_TOKEN',
        ]);
        $claim = fn (): string => PaymentAttempt::query()->create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'payment_request_id' => $request->id,
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago',
            'environment' => 'sandbox',
            'provider_account' => 'seller-race',
            'external_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'purpose' => 'full_outstanding',
            'state' => 'creating',
            'source_amount_minor' => 50_000,
            'source_currency' => 'ARS',
            'charge_amount_minor' => 50_000,
            'charge_currency' => 'ARS',
        ])->id;

        $results = $this->concurrently([$claim, $claim], $tenant, $membership);
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, PaymentAttempt::query()->where('payment_request_id', $request->id)->count());
    }

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

    public function test_concurrent_document_request_deduplication_claims_create_one_request(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, , $user, $membership] = $this->tenantEnvironment();
        $deduplicationKey = 'postgres-document-request-race-0001';

        $claim = function () use ($tenant, $user, $deduplicationKey): string {
            $id = (string) Str::uuid();
            DB::table('document_generation_requests')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'requested_by' => $user->id,
                'kind' => DocumentKind::Itinerary->value,
                'locale' => 'en',
                'status' => DocumentGenerationStatus::Pending->value,
                'source_snapshot' => '{}',
                'source_checksum' => str_repeat('a', 64),
                'deduplication_key' => $deduplicationKey,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        };

        $results = $this->concurrently([$claim, $claim], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('document_generation_requests')->where('deduplication_key', $deduplicationKey)->count());
    }

    public function test_concurrent_report_export_deduplication_claims_create_one_export(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $deduplicationKey = 'postgres-report-export-race-0001';

        $claim = function () use ($tenant, $property, $user, $deduplicationKey): string {
            $id = (string) Str::uuid();
            DB::table('report_exports')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'requested_by' => $user->id,
                'property_id' => $property->id,
                'kind' => ReportExportKind::Arrivals->value,
                'format' => ReportExportFormat::Csv->value,
                'locale' => 'en',
                'filters' => '{}',
                'filter_checksum' => str_repeat('b', 64),
                'deduplication_key' => $deduplicationKey,
                'status' => ReportExportStatus::Pending->value,
                'row_count' => 0,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id;
        };

        $results = $this->concurrently([$claim, $claim], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, collect($results)->where('ok', false)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('report_exports')->where('deduplication_key', $deduplicationKey)->count());
    }

    public function test_concurrent_provider_event_workers_claim_one_event_once(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $attempt, , $membership] = $this->providerPaymentEnvironment('event-race-payment');
        $event = $this->providerEvent($attempt, 'event-race-delivery', 'event-race-payment');

        $process = fn (): string => app(ProcessProviderEvent::class)->handle($event)->processing_state->value;
        $results = $this->concurrently([$process, $process], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $event->fresh()->attempt_count);
        $this->assertSame(ProviderEventState::Processed, $event->fresh()->processing_state);
        $this->assertSame(1, Payment::query()->where('provider_reference', 'event-race-payment')->count());
        $this->assertSame(1, $reservation->folioLines()->where('payment_id', Payment::query()->where('provider_reference', 'event-race-payment')->value('id'))->count());
    }

    public function test_manual_reconcile_racing_webhook_leaves_one_succeeded_payment_effect(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $attempt, , $membership] = $this->providerPaymentEnvironment('manual-webhook-race-payment', process: false);
        $user = $membership->user;
        $manual = app(PaymentService::class)->recordManual([
            'reservation_id' => $reservation->id,
            'method' => 'bank_transfer',
            'amount_minor' => 10_000,
        ], $user->id);
        $event = $this->providerEvent($attempt, 'manual-webhook-race-delivery', 'manual-webhook-race-payment');

        $results = $this->concurrently([
            fn (): string => app(PaymentService::class)->reconcile($manual, $user->id)->status->value,
            fn (): string => app(ProcessProviderEvent::class)->handle($event)->processing_state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertContains(collect($results)->where('ok', true)->count(), [1, 2], json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Succeeded)->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', FolioLineType::Payment)->count());
        $this->assertSame(0, app(FolioService::class)->summary($reservation)['balance_minor']);
    }

    public function test_concurrent_refund_recovery_workers_post_one_local_completion(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $attempt, $payment, $membership] = $this->providerPaymentEnvironment('refund-race-payment');
        $user = $membership->user;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, $user->id);
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Provider recovery race', $user->id);
        $refund = ProviderRefund::query()->create([
            'property_id' => $attempt->property_id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $request->id,
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'source_amount_minor' => 5_000,
            'source_currency' => 'ARS',
            'charge_amount_minor' => 5_000,
            'charge_currency' => 'ARS',
            'idempotency_key' => (string) Str::uuid(),
            'provider_payment_id' => $payment->provider_reference,
            'state' => 'processing',
        ]);
        $fake = app(PaymentGatewayFactory::class);
        $this->assertInstanceOf(FakePaymentGateway::class, $fake);
        $fake->refundResults['refund-race-id'] = new ProviderRefundData('refund-race-id', $payment->provider_reference, 'approved', 5_000, 'ARS', $attempt->provider_account);

        $recover = fn (): string => app(RecoverProviderRefund::class)->handle($refund, 'refund-race-id', $user->id)->state->value;
        $results = $this->concurrently([$recover, $recover], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('succeeded', $refund->fresh()->state->value);
        $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
    }

    public function test_refund_execute_racing_authoritative_recovery_posts_one_effect(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $attempt, $payment, $membership] = $this->providerPaymentEnvironment('execute-recover-race-payment');
        $user = $membership->user;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, $user->id);
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Execute versus recover', $user->id);
        $idempotencyKey = (string) Str::uuid();
        $providerRefundId = 'refund-'.$idempotencyKey;
        $refund = ProviderRefund::query()->create([
            'property_id' => $attempt->property_id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $request->id,
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'source_amount_minor' => 5_000,
            'source_currency' => 'ARS',
            'charge_amount_minor' => 5_000,
            'charge_currency' => 'ARS',
            'idempotency_key' => $idempotencyKey,
            'provider_payment_id' => $payment->provider_reference,
            'state' => 'processing',
        ]);
        $fake = app(PaymentGatewayFactory::class);
        $this->assertInstanceOf(FakePaymentGateway::class, $fake);
        $fake->refundResults[$providerRefundId] = new ProviderRefundData($providerRefundId, $payment->provider_reference, 'approved', 5_000, 'ARS', 'seller-1');

        $results = $this->concurrently([
            fn (): string => app(ExecuteProviderRefund::class)->handle($request, $user->id)->state->value,
            fn (): string => app(RecoverProviderRefund::class)->handle($refund, $providerRefundId, $user->id)->state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('succeeded', $refund->fresh()->state->value);
        $this->assertSame($providerRefundId, $refund->fresh()->provider_refund_id);
        $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', FolioLineType::Refund)->count());
    }

    public function test_concurrent_refund_and_lost_chargeback_never_over_apply_payment_value(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, $reservation, $attempt, $payment, $membership] = $this->providerPaymentEnvironment('refund-chargeback-payment');
        $user = $membership->user;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -10_000, $user->id);
        $request = app(RequestRefund::class)->handle($reservation, $payment, 10_000, 'Competing chargeback', $user->id);
        $refund = ProviderRefund::query()->create([
            'property_id' => $attempt->property_id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $request->id,
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'source_amount_minor' => 10_000,
            'source_currency' => 'ARS',
            'charge_amount_minor' => 10_000,
            'charge_currency' => 'ARS',
            'idempotency_key' => (string) Str::uuid(),
            'provider_payment_id' => $payment->provider_reference,
            'state' => 'processing',
        ]);
        $fake = app(PaymentGatewayFactory::class);
        $this->assertInstanceOf(FakePaymentGateway::class, $fake);
        $fake->refundResults['refund-competing-id'] = new ProviderRefundData('refund-competing-id', $payment->provider_reference, 'approved', 10_000, 'ARS', 'seller-1');
        $fake->disputes['chargeback-competing-id'] = new ProviderDisputeData(
            'chargeback-competing-id', $payment->provider_reference, 'charged_back', 'settled', 10_000, 'ARS',
            $attempt->provider_account, 'general', false, false, null, CarbonImmutable::now()->subDay(), CarbonImmutable::now(),
        );
        $event = $this->providerEvent($attempt, 'chargeback-competing-delivery', 'chargeback-competing-id', 'topic_chargebacks_wh');

        $results = $this->concurrently([
            fn (): string => app(RecoverProviderRefund::class)->handle($refund, 'refund-competing-id', $user->id)->state->value,
            fn (): string => app(ProcessProviderEvent::class)->handle($event)->processing_state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $refundEffect = (int) $reservation->changes()->where('type', 'refund_completed')->where('status', 'completed')->sum('amount_minor');
        $chargebackEffect = (int) $reservation->folioLines()->where('metadata->provider_dispute_effect', 'chargeback')->sum('gross_amount_minor');
        $this->assertLessThanOrEqual($payment->amount_minor, $refundEffect + $chargebackEffect, json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertContains(collect($results)->where('ok', true)->count(), [1, 2], json_encode($results, JSON_THROW_ON_ERROR));
    }

    public function test_concurrent_settlement_replays_create_one_immutable_revision(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, , $attempt, , $membership] = $this->providerPaymentEnvironment('settlement-race-payment', process: false);
        $payment = new ProviderPayment(
            'settlement-race-payment', $attempt->external_reference, 'approved', 'accredited', 10_000, 'ARS', $attempt->provider_account,
            ['gross_minor' => 10_000, 'fee_minor' => 500, 'tax_minor' => 100, 'net_minor' => 9_400],
        );

        $record = fn (): string => app(RecordSettlementRevision::class)->handle($attempt, $payment)->id;
        $results = $this->concurrently([$record, $record], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('settlement_entries', 1);
        $this->assertDatabaseCount('settlement_entry_revisions', 1);
    }

    public function test_expiry_racing_authoritative_approval_never_applies_stale_money(): void
    {
        $this->requirePostgresConcurrency();
        [$tenant, , $attempt, , $membership] = $this->providerPaymentEnvironment('expiry-race-payment', process: false);
        $attempt->paymentRequest()->update(['expires_at' => now()->subSecond()]);
        $event = $this->providerEvent($attempt, 'expiry-race-delivery', 'expiry-race-payment');

        $results = $this->concurrently([
            fn (): string => (string) Artisan::call('payments:expire-requests', ['--tenant' => $tenant->id]),
            fn (): string => app(ProcessProviderEvent::class)->handle($event)->processing_state->value,
        ], $tenant, $membership);

        app(TenantContext::class)->set($tenant, $membership);
        $this->assertSame(2, collect($results)->where('ok', true)->count(), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame('expired', $attempt->paymentRequest->fresh()->state->value);
        $this->assertDatabaseMissing('payments', ['provider_reference' => 'expiry-race-payment']);
        $this->assertSame(0, $attempt->reservation->folioLines()->whereNotNull('payment_id')->count());
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

    /** @return array{Tenant, Reservation, PaymentAttempt, Payment|null, Membership} */
    private function providerPaymentEnvironment(string $providerPaymentId, bool $process = true): array
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment();
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
        ]);
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, $user->id)->request;
        $connection = app(IntegrationConnectionService::class)->configure(
            'provider-race-'.$providerPaymentId,
            'payment',
            ['return_url_base' => 'https://inn.test'],
            'env:RACE_GATEWAY_TOKEN',
            $property->id,
            'mercado_pago',
            'checkout_pro',
            'seller-race',
            'sandbox',
            ['payment.hosted_checkout'],
        );
        $connection = app(IntegrationConnectionService::class)->enable($connection, $user->id, 'Enable PostgreSQL payment race fixture.');
        app(EndpointKeyService::class)->rotate($connection, 0, $user->id, 'Issue PostgreSQL payment race webhook endpoint.');
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $attempt = app(CreateProviderCheckout::class)->handle($request, $connection);
        $fake->payments[$providerPaymentId] = new ProviderPayment(
            $providerPaymentId, $attempt->external_reference, 'approved', 'accredited', 10_000, 'ARS', 'seller-race',
        );
        $payment = null;
        if ($process) {
            app(ProcessProviderEvent::class)->handle($this->providerEvent($attempt, 'setup-'.$providerPaymentId, $providerPaymentId));
            $payment = Payment::query()->where('provider_reference', $providerPaymentId)->sole();
        }

        return [$tenant, $reservation, $attempt->fresh(), $payment, $membership];
    }

    private function providerEvent(PaymentAttempt $attempt, string $delivery, string $resource, string $topic = 'payment'): ProviderEvent
    {
        return ProviderEvent::query()->create([
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'delivery_id' => $delivery,
            'topic' => $topic,
            'event_type' => $topic,
            'action' => $topic.'.updated',
            'resource_id' => $resource,
            'signature_valid' => true,
            'received_at' => now(),
            'processing_state' => ProviderEventState::Received,
            'raw_body_checksum' => hash('sha256', $delivery),
        ]);
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

    private function terminalTenderInput(Reservation $reservation, string $key): FrontDeskPaymentInput
    {
        return new FrontDeskPaymentInput(
            reservationId: $reservation->id,
            channel: PaymentChannel::ExternalTerminal,
            amountMinor: 10_000,
            idempotencyKey: $key,
            processorAlias: 'Synthetic processor',
            merchantAccountAlias: 'Front desk merchant',
            terminalIdentifier: 'Terminal 01',
            transactionReference: 'Race receipt 100',
            authorizationReference: 'Race approval 200',
            batchReference: 'Race batch 300',
            cardBrand: 'Test brand',
            cardLastFour: '0042',
        );
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
