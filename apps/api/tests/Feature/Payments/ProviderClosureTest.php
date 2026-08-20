<?php

namespace Tests\Feature\Payments;

use App\Contracts\Payments\PaymentGatewayFactory;
use App\Data\Payments\ProviderDispute;
use App\Data\Payments\ProviderPayment;
use App\Data\Payments\ProviderRefund as ProviderRefundData;
use App\Enums\FolioLineType;
use App\Enums\MembershipRole;
use App\Enums\PaymentRequestPurpose;
use App\Enums\ProviderEventState;
use App\Enums\ReservationStatus;
use App\Filament\Resources\SettlementReportRows\SettlementReportRowResource;
use App\Jobs\ProcessProviderEventJob;
use App\Models\DocumentGenerationRequest;
use App\Models\IntegrationConnection;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Property;
use App\Models\ProviderEvent;
use App\Models\ProviderRefund;
use App\Models\Reservation;
use App\Models\SettlementReportImport;
use App\Models\SettlementReportRow;
use App\Models\Tenant;
use App\Services\FolioService;
use App\Services\Payments\CreateProviderCheckout;
use App\Services\Payments\ImportMercadoPagoSettlementReport;
use App\Services\Payments\IssuePaymentRequest;
use App\Services\Payments\ProcessProviderEvent;
use App\Services\Payments\RecoverProviderRefund;
use App\Services\Payments\ResolveSettlementVariance;
use App\Services\RequestRefund;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesTenant;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class ProviderClosureTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_expiry_schedule_is_named_single_server_overlap_protected_and_expires_attempt_once(): void
    {
        [, $property] = $this->tenantEnvironment();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 50_000);
        $request = app(IssuePaymentRequest::class)->handle(
            $reservation,
            PaymentRequestPurpose::FullOutstanding,
            null,
            null,
            auth()->id(),
            now()->addMinute(),
        )->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $this->connection());
        $request->update(['expires_at' => now()->subSecond()]);

        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'payments:expire-requests'),
        );
        $this->assertNotNull($event);
        $this->assertSame('payments:expire-requests', $event->description);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(10, $event->expiresAt);

        Cache::flush();
        $secondNode = new Schedule;
        $secondEvent = $secondNode->command('payments:expire-requests')
            ->name('payments:expire-requests')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();
        $boundary = CarbonImmutable::now()->startOfMinute();
        $this->assertTrue(app(Schedule::class)->serverShouldRun($event, $boundary));
        $this->assertFalse($secondNode->serverShouldRun($secondEvent, $boundary));
        Cache::flush();
        CarbonImmutable::setTestNow($boundary);
        $this->assertTrue($event->mutex->create($event));
        $this->assertFalse($secondEvent->mutex->create($secondEvent));
        CarbonImmutable::setTestNow($boundary->addMinutes(11));
        $this->assertTrue($secondEvent->mutex->create($secondEvent));
        $event->mutex->forget($event);
        $secondEvent->mutex->forget($secondEvent);
        $this->assertTrue($event->mutex->create($event));
        $event->mutex->forget($event);
        $secondEvent->mutex->forget($secondEvent);
        CarbonImmutable::setTestNow();

        $request->update(['expires_at' => now()]);
        $this->assertSame(0, Artisan::call('payments:expire-requests'));
        $this->assertSame('expired', $request->fresh()->state->value);
        $this->assertSame('expired', $attempt->fresh()->state->value);
        $firstProcessedAt = $attempt->fresh()->last_processed_at;
        $this->assertSame(0, Artisan::call('payments:expire-requests'));
        $this->assertTrue($firstProcessedAt->equalTo($attempt->fresh()->last_processed_at));
        $this->post('/pay/test-token/checkout')->assertNotFound();
    }

    public function test_provider_dashboard_refund_recovery_is_authoritative_idempotent_and_completes_one_folio_effect(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [$reservation, $attempt, $payment] = $this->approvedPayment($property->id, $fake, 'refund-source', 20_000);
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cancellation credit', 1000, -5_000, auth()->id());
        $request = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Provider dashboard fallback', auth()->id());
        $refund = ProviderRefund::query()->create([
            'property_id' => $property->id,
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
            'idempotency_key' => fake()->uuid(),
            'provider_payment_id' => $payment->provider_reference,
            'state' => 'processing',
            'last_attempted_at' => now()->subHour(),
        ]);
        $fake->refundResults['dashboard-refund-1'] = new ProviderRefundData('dashboard-refund-1', $payment->provider_reference, 'approved', 5_000, 'ARS', 'seller-1');

        app(RecoverProviderRefund::class)->handle($refund, 'dashboard-refund-1', auth()->id());
        unset($fake->refundResults['dashboard-refund-1']);
        app(RecoverProviderRefund::class)->handle($refund->fresh(), 'dashboard-refund-1', auth()->id());

        $this->assertSame('succeeded', $refund->fresh()->state->value);
        $this->assertCount(1, $fake->fetchRefundCalls);
        $this->assertSame(1, $request->events()->where('type', 'refund_completed')->count());
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
        $this->expectException(\DomainException::class);
        app(RecoverProviderRefund::class)->handle($refund->fresh(), 'different-refund-id', auth()->id());
    }

    public function test_chargeback_lifecycle_is_append_only_and_applies_then_reverses_one_folio_effect(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [$reservation, $attempt] = $this->approvedPayment($property->id, $fake, 'chargeback-source', 20_000);

        foreach ([
            ['in_process', null, 'under_review'],
            ['settled', false, 'lost'],
            ['reimbursed', true, 'won'],
        ] as $index => [$detail, $coverage, $expected]) {
            $fake->disputes['chargeback-1'] = new ProviderDispute(
                'chargeback-1',
                'chargeback-source',
                'charged_back',
                $detail,
                20_000,
                'ARS',
                'seller-1',
                'reason-'.$index,
                $coverage,
                false,
                null,
                CarbonImmutable::now()->subDay(),
                CarbonImmutable::now()->addSeconds($index),
            );
            $event = $this->event($attempt, 'chargeback-'.$index, 'chargeback-1', 'topic_chargebacks_wh');
            app(ProcessProviderEvent::class)->handle($event);
            $this->assertDatabaseHas('provider_disputes', ['provider_dispute_id' => 'chargeback-1', 'state' => $expected]);
        }

        $dispute = $attempt->providerDisputes()->sole();
        $this->assertSame(3, $dispute->revisions()->count());
        $this->assertSame('reason-0', $dispute->revisions()->first()->reason);
        $this->assertSame('chargeback-1', $dispute->revisions()->first()->provider_facts['provider_dispute_id']);
        $this->assertNotNull($dispute->revisions()->first()->provider_created_at);
        $this->assertSame(1, $reservation->folioLines()->where('metadata->provider_dispute_effect', 'chargeback')->count());
        $chargeback = $reservation->folioLines()->where('metadata->provider_dispute_effect', 'chargeback')->sole();
        $this->assertNotNull($chargeback->reversal);
        $this->assertSame(0, app(FolioService::class)->summary($reservation)['balance_minor']);
        $this->expectException(\LogicException::class);
        $dispute->revisions()->firstOrFail()->update(['status' => 'tampered']);
    }

    public function test_settlement_revisions_preserve_provider_facts_and_variance_actions(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [, $attempt] = $this->approvedPayment($property->id, $fake, 'settlement-source', 10_000, [
            'gross_minor' => 10_000,
            'fee_minor' => 1_000,
            'tax_minor' => null,
            'withholding_minor' => null,
            'net_minor' => 8_500,
        ]);
        $entry = $attempt->integrationConnection->settlementEntries()->sole();
        $this->assertSame('variance', $entry->reconciliation_state->value);
        app(ResolveSettlementVariance::class)->handle($entry, 'investigate', 'Provider tax report requested.', $user->id);

        $fake->payments['settlement-source'] = new ProviderPayment(
            'settlement-source', $attempt->external_reference, 'approved', 'accredited', 10_000, 'ARS', 'seller-1', [
                'gross_minor' => 10_000,
                'fee_minor' => 1_000,
                'tax_minor' => 200,
                'withholding_minor' => 300,
                'net_minor' => 8_500,
                'payout_identity' => null,
                'payout_date' => null,
            ],
        );
        app(ProcessProviderEvent::class)->handle($this->event($attempt, 'settlement-second', 'settlement-source'));

        $entry = $entry->fresh('revisions');
        $this->assertSame(2, $entry->revisions->count());
        $this->assertNull($entry->revisions->first()->tax_minor);
        $this->assertSame(200, $entry->revisions->last()->tax_minor);
        $this->assertSame('matched', $entry->reconciliation_state->value);
        $this->assertNull($entry->payout_identity);
        $this->expectException(\LogicException::class);
        $entry->revisions->first()->update(['gross_minor' => 1]);
    }

    public function test_http_webhook_dispatches_provider_queue_records_duplicate_and_unknown_account_stays_unapplied(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        Queue::fake();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 20_000);
        $connection = $this->connection();
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $connection);
        $fake->payments['unknown-account-payment'] = new ProviderPayment(
            'unknown-account-payment', $attempt->external_reference, 'approved', null, 20_000, 'ARS', 'different-seller',
        );
        $body = json_encode(['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => 'unknown-account-payment']], JSON_THROW_ON_ERROR);
        $url = '/api/v1/payment-webhooks/'.str_repeat('w', 48).'?data.id=unknown-account-payment';
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUEST_ID' => 'delivery-http-1',
            'HTTP_X_SIGNATURE' => 'fake-verified-by-contract-double',
        ];

        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
        Queue::assertPushedOn('provider-events', ProcessProviderEventJob::class);
        $event = ProviderEvent::query()->where('processing_state', 'received')->sole();
        app(ProcessProviderEvent::class)->handle($event);
        $this->assertSame('mismatched', $event->fresh()->processing_state->value);
        $this->assertDatabaseCount('payments', 0);

        $this->call('POST', $url, [], [], [], $server, $body)->assertOk();
        $this->assertDatabaseHas('provider_events', ['duplicate_of_id' => $event->id, 'processing_state' => 'duplicate']);
        Queue::assertPushed(ProcessProviderEventJob::class, 1);
    }

    public function test_stale_processing_event_is_reclaimed_after_worker_crash(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 20_000);
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $this->connection());
        $fake->payments['crash-retry-payment'] = new ProviderPayment(
            'crash-retry-payment', $attempt->external_reference, 'approved', 'accredited', 20_000, 'ARS', 'seller-1',
        );
        $event = $this->event($attempt, 'crash-retry-delivery', 'crash-retry-payment');
        $event->timestamps = false;
        $event->forceFill(['processing_state' => ProviderEventState::Processing, 'updated_at' => now()->subMinutes(2)])->save();
        $event->timestamps = true;

        app(ProcessProviderEvent::class)->handle($event);

        $this->assertSame('processed', $event->fresh()->processing_state->value);
        $this->assertSame(1, $event->fresh()->attempt_count);
        $this->assertSame(1, Payment::query()->where('provider_reference', 'crash-retry-payment')->count());
    }

    public function test_claim_topic_is_left_unapplied_without_calling_chargeback_endpoint(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 20_000);
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $this->connection());

        $event = app(ProcessProviderEvent::class)->handle($this->event($attempt, 'claim-delivery', 'claim-1', 'topic_claims_wh'));

        $this->assertSame('mismatched', $event->processing_state->value);
        $this->assertStringContainsString('unsupported', (string) $event->last_error);
        $this->assertDatabaseCount('provider_disputes', 0);
    }

    public function test_authoritative_payment_and_chargeback_resource_ids_must_match_the_event(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 20_000);
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $this->connection());
        $fake->payments['event-payment-id'] = new ProviderPayment('different-payment-id', $attempt->external_reference, 'approved', 'accredited', 20_000, 'ARS', 'seller-1');

        $paymentEvent = app(ProcessProviderEvent::class)->handle($this->event($attempt, 'payment-resource-mismatch', 'event-payment-id'));
        $this->assertSame('mismatched', $paymentEvent->processing_state->value);
        $this->assertDatabaseCount('payments', 0);

        $fake->disputes['event-chargeback-id'] = new ProviderDispute(
            'different-chargeback-id', 'unapplied-payment', 'charged_back', 'settled', 20_000, 'ARS', 'seller-1',
        );
        $chargebackEvent = app(ProcessProviderEvent::class)->handle($this->event($attempt, 'chargeback-resource-mismatch', 'event-chargeback-id', 'topic_chargebacks_wh'));
        $this->assertSame('mismatched', $chargebackEvent->processing_state->value);
        $this->assertDatabaseCount('provider_disputes', 0);
    }

    public function test_multiple_lost_disputes_cannot_apply_more_than_the_payment(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [$reservation, $attempt] = $this->approvedPayment($property->id, $fake, 'multi-dispute-payment', 20_000);

        foreach (['dispute-a', 'dispute-b'] as $id) {
            $fake->disputes[$id] = new ProviderDispute($id, 'multi-dispute-payment', 'charged_back', 'settled', 15_000, 'ARS', 'seller-1');
            app(ProcessProviderEvent::class)->handle($this->event($attempt, 'delivery-'.$id, $id, 'topic_chargebacks_wh'));
        }

        $lines = $reservation->folioLines()->where('metadata->provider_dispute_effect', 'chargeback')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(20_000, $lines->sum('net_amount_minor'));
    }

    public function test_approved_provider_payment_enqueues_exactly_one_system_receipt_request_on_replay(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [, $attempt, $payment] = $this->approvedPayment($property->id, $fake, 'receipt-payment', 10_000);

        app(ProcessProviderEvent::class)->handle($this->event($attempt, 'receipt-replay', 'receipt-payment'));

        $requests = DocumentGenerationRequest::query()->where('payment_id', $payment->id)->where('kind', 'payment_receipt')->get();
        $this->assertCount(1, $requests);
        $this->assertNull($requests->sole()->requested_by);
    }

    public function test_account_and_released_money_reports_are_append_only_exact_and_keep_payout_rows_account_level(): void
    {
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        $reservation = $this->reservation($property->id, 10_000);
        $connection = $this->connection();
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $connection);
        $attempt->update(['external_reference' => '11111111-1111-4111-8111-111111111111', 'provider_payment_id' => 'pay-report-001']);
        $importer = app(ImportMercadoPagoSettlementReport::class);
        $account = base_path('tests/Fixtures/payments/mercado_pago_account_money.csv');
        $released = base_path('tests/Fixtures/payments/mercado_pago_released_money.csv');

        $this->assertSame(4, $importer->handle($connection, $account, 'account_money', 'account-report-001', true, ['site' => 'MLA']));
        $this->assertSame(4, $importer->handle($connection, $account, 'account_money', 'account-report-001', true, ['site' => 'MLA']));
        $this->assertSame(11, $importer->handle($connection, $released, 'released_money', 'released-report-001', true, ['site' => 'MLA']));

        $this->assertSame(2, SettlementReportImport::query()->count());
        $this->assertSame(15, SettlementReportRow::query()->count());
        $this->assertSame(5, SettlementReportRow::query()->where('application_state', 'account_level')->count());
        $this->assertSame(2, SettlementReportRow::query()->where('row_kind', 'ACCOUNT_AVAILABLE_BALANCE')->count());
        $this->assertSame(1, SettlementReportRow::query()->where('row_kind', 'WITHDRAWAL')->count());
        $this->assertSame(1, SettlementReportRow::query()->where('row_kind', 'WITHDRAWAL_CANCEL')->count());
        $this->assertSame(0, SettlementReportRow::query()->where('application_state', 'mismatched')->count());
        $this->assertTrue(SettlementReportRow::query()->get()->every(fn (SettlementReportRow $row): bool => ! array_key_exists('PAYER_NAME', $row->canonical_row)));
        $this->assertStringNotContainsString('Sensitive Payer', (string) DB::table('settlement_entry_revisions')->pluck('provider_facts')->implode('|'));
        $entry = $connection->settlementEntries()->sole();
        $this->assertSame(2, $entry->revisions()->count());
        $this->assertSame(10_000, $entry->gross_minor);
        $this->assertSame(2_000, $entry->refunded_minor);
        $this->assertSame(4_000, $entry->chargeback_minor);
        $this->assertSame(25, $entry->tax_minor);
        $this->assertSame(15, $entry->withholding_minor);
        $this->assertSame(3_785, $entry->net_minor);
        $this->assertNull($entry->payout_identity);
        $this->assertNull($entry->payout_status);
        $this->assertSame('released_money_report', $entry->revisions()->reorder('revision', 'desc')->firstOrFail()->provider_facts['fact_source']);
        $accountRevision = $entry->revisions()->where('revision', 1)->sole();
        $this->assertNull($accountRevision->tax_minor);
        $this->assertSame(50, $accountRevision->withholding_minor);

        $reissued = tempnam(sys_get_temp_dir(), 'account-report-reissued-');
        $mismatched = tempnam(sys_get_temp_dir(), 'account-report-mismatch-');
        $wrongAccountAmount = tempnam(sys_get_temp_dir(), 'account-report-wrong-amount-');
        $wrongReleasedAmount = tempnam(sys_get_temp_dir(), 'released-report-wrong-amount-');
        $overDeducted = tempnam(sys_get_temp_dir(), 'account-report-over-deducted-');
        $mixedReleased = tempnam(sys_get_temp_dir(), 'released-report-mixed-');
        $duplicateSettlement = tempnam(sys_get_temp_dir(), 'account-report-duplicate-settlement-');
        $unknownAccount = tempnam(sys_get_temp_dir(), 'account-report-unknown-kind-');
        $unknownReleased = tempnam(sys_get_temp_dir(), 'released-report-unknown-kind-');
        $excessiveWithholding = tempnam(sys_get_temp_dir(), 'account-report-excessive-withholding-');
        $this->assertIsString($reissued);
        $this->assertIsString($mismatched);
        $this->assertIsString($wrongAccountAmount);
        $this->assertIsString($wrongReleasedAmount);
        $this->assertIsString($overDeducted);
        $this->assertIsString($mixedReleased);
        $this->assertIsString($duplicateSettlement);
        $this->assertIsString($unknownAccount);
        $this->assertIsString($unknownReleased);
        $this->assertIsString($excessiveWithholding);
        try {
            $source = file_get_contents($account);
            $this->assertIsString($source);
            file_put_contents($reissued, $source."\n");
            file_put_contents($mismatched, str_replace('seller-1', 'different-seller', $source));
            $this->assertSame(4, $importer->handle($connection, $reissued, 'account_money', 'account-report-001', true, ['site' => 'MLA']));
            $this->assertSame('variance', $entry->fresh()->reconciliation_state->value);
            $this->assertSame(2, SettlementReportImport::query()->where('provider_report_id', 'account-report-001')->count());
            $beforeMismatch = $entry->revisions()->count();
            $this->assertSame(4, $importer->handle($connection, $mismatched, 'account_money', 'account-report-mismatch', true, ['site' => 'MLA']));
            $this->assertSame($beforeMismatch, $entry->revisions()->count());
            $this->assertSame(3, SettlementReportRow::query()->where('settlement_report_import_id', SettlementReportImport::query()->where('provider_report_id', 'account-report-mismatch')->value('id'))->where('application_state', 'mismatched')->count());

            $accountLines = file($account, FILE_IGNORE_NEW_LINES);
            $releasedLines = file($released, FILE_IGNORE_NEW_LINES);
            $this->assertIsArray($accountLines);
            $this->assertIsArray($releasedLines);
            file_put_contents($wrongAccountAmount, $accountLines[0]."\n".str_replace(',100.00,ARS,', ',101.00,ARS,', $accountLines[1])."\n");
            file_put_contents($wrongReleasedAmount, $releasedLines[0]."\n".str_replace(',100.00,1.00,', ',101.00,1.00,', $releasedLines[1])."\n");
            $beforeWrongAmount = $entry->revisions()->count();
            $this->assertSame(1, $importer->handle($connection, $wrongAccountAmount, 'account_money', 'account-report-wrong-amount', true, ['site' => 'MLA']));
            $this->assertSame(1, $importer->handle($connection, $wrongReleasedAmount, 'released_money', 'released-report-wrong-amount', true, ['site' => 'MLA']));
            $this->assertSame(2, SettlementReportRow::query()->whereIn('settlement_report_import_id', SettlementReportImport::query()->whereIn('provider_report_id', ['account-report-wrong-amount', 'released-report-wrong-amount'])->pluck('id'))->where('application_state', 'mismatched')->count());
            $this->assertSame($beforeWrongAmount, $entry->revisions()->count());

            $refundSixty = str_replace('-20.00', '-60.00', $accountLines[2]);
            file_put_contents($overDeducted, implode("\n", [$accountLines[0], $accountLines[1], $refundSixty, $refundSixty])."\n");
            $this->assertSame(3, $importer->handle($connection, $overDeducted, 'account_money', 'account-report-over-deducted', true, ['site' => 'MLA']));
            $overImport = SettlementReportImport::query()->where('provider_report_id', 'account-report-over-deducted')->sole();
            $this->assertSame(3, $overImport->rows()->where('application_state', 'mismatched')->count());
            $this->assertSame($beforeWrongAmount, $entry->revisions()->count());

            file_put_contents($mixedReleased, implode("\n", [$releasedLines[0], $releasedLines[1], str_replace(',ARS,20.00,', ',USD,20.00,', $releasedLines[2])])."\n");
            file_put_contents($duplicateSettlement, implode("\n", [$accountLines[0], $accountLines[1], $accountLines[1]])."\n");
            $this->assertSame(2, $importer->handle($connection, $mixedReleased, 'released_money', 'released-report-mixed', true, ['site' => 'MLA']));
            $this->assertSame(2, $importer->handle($connection, $duplicateSettlement, 'account_money', 'account-report-duplicate-settlement', true, ['site' => 'MLA']));
            $invalidImports = SettlementReportImport::query()->whereIn('provider_report_id', ['released-report-mixed', 'account-report-duplicate-settlement'])->pluck('id');
            $this->assertSame(4, SettlementReportRow::query()->whereIn('settlement_report_import_id', $invalidImports)->where('application_state', 'mismatched')->count());
            $this->assertSame($beforeWrongAmount, $entry->revisions()->count());

            file_put_contents($unknownAccount, implode("\n", [$accountLines[0], $accountLines[1], str_replace(',REFUND,', ',UNSUPPORTED_MOVEMENT,', $accountLines[2])])."\n");
            file_put_contents($unknownReleased, implode("\n", [$releasedLines[0], $releasedLines[1], str_replace(',refund,', ',unsupported_movement,', $releasedLines[2])])."\n");
            file_put_contents($excessiveWithholding, $accountLines[0]."\n".str_replace(',0.50,cordoba', ',101.00,cordoba', $accountLines[1])."\n");
            $this->assertSame(2, $importer->handle($connection, $unknownAccount, 'account_money', 'account-report-unknown-kind', true, ['site' => 'MLA']));
            $this->assertSame(2, $importer->handle($connection, $unknownReleased, 'released_money', 'released-report-unknown-kind', true, ['site' => 'MLA']));
            $this->assertSame(1, $importer->handle($connection, $excessiveWithholding, 'account_money', 'account-report-excessive-withholding', true, ['site' => 'MLA']));
            $unsupportedImports = SettlementReportImport::query()->whereIn('provider_report_id', ['account-report-unknown-kind', 'released-report-unknown-kind', 'account-report-excessive-withholding'])->pluck('id');
            $this->assertSame(5, SettlementReportRow::query()->whereIn('settlement_report_import_id', $unsupportedImports)->where('application_state', 'mismatched')->count());
            $this->assertSame(5, SettlementReportRowResource::getEloquentQuery()->whereIn('settlement_report_import_id', $unsupportedImports)->count());
            $this->assertSame($beforeWrongAmount, $entry->revisions()->count());
        } finally {
            unlink($reissued);
            unlink($mismatched);
            unlink($wrongAccountAmount);
            unlink($wrongReleasedAmount);
            unlink($overDeducted);
            unlink($mixedReleased);
            unlink($duplicateSettlement);
            unlink($unknownAccount);
            unlink($unknownReleased);
            unlink($excessiveWithholding);
        }
    }

    public function test_webhook_key_is_globally_unique_across_tenants(): void
    {
        $this->tenantEnvironment();
        $this->connection();
        $secondTenant = Tenant::factory()->create();
        app(TenantContext::class)->set($secondTenant);

        $this->expectException(UniqueConstraintViolationException::class);
        IntegrationConnection::query()->create([
            'name' => 'collision', 'type' => 'payment',
            'configuration' => ['provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-2', 'webhook_key' => str_repeat('w', 48)],
            'secret_reference' => 'env:MP_TEST_TOKEN',
        ]);
    }

    public function test_payment_identity_indexes_scope_provider_rows_and_reject_manual_provider_identity(): void
    {
        [, $property] = $this->tenantEnvironment();
        $first = $this->reservation($property->id, 10_000);
        $second = $this->reservation($property->id, 10_000);
        foreach ([['sandbox', 'seller-a'], ['production', 'seller-b']] as [$environment, $account]) {
            Payment::query()->create([
                'reservation_id' => $first->id, 'status' => 'succeeded', 'method' => 'provider', 'origin' => 'provider',
                'provider' => 'mercado_pago', 'environment' => $environment, 'provider_account' => $account,
                'provider_reference' => 'same-provider-id', 'currency' => 'ARS', 'amount_minor' => 10_000, 'processed_at' => now(),
            ]);
        }
        $this->assertSame(2, Payment::query()->where('provider_reference', 'same-provider-id')->count());
        $this->expectException(ValidationException::class);
        Payment::query()->create([
            'reservation_id' => $second->id, 'status' => 'pending', 'method' => 'manual', 'origin' => 'manual',
            'provider' => 'bank', 'provider_reference' => 'manual-ref', 'currency' => 'ARS', 'amount_minor' => 10_000,
        ]);
    }

    public function test_deterministic_transport_rejects_a_production_provider_connection_in_testing_app(): void
    {
        $this->tenantEnvironment();
        $connection = IntegrationConnection::query()->create([
            'name' => 'production-fixture-rejected',
            'type' => 'payment',
            'configuration' => [
                'provider' => 'mercado_pago', 'environment' => 'production', 'provider_account' => 'seller-production',
                'webhook_key' => str_repeat('p', 48), 'webhook_secret_reference' => 'env:UNUSED_WEBHOOK_SECRET',
                'transport' => 'deterministic_fixture', 'fixture' => [],
            ],
            'secret_reference' => 'env:UNUSED_PROVIDER_TOKEN',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit sandbox provider connections');
        app(PaymentGatewayFactory::class)->for($connection);
    }

    public function test_finance_report_exception_queue_is_property_scoped_and_hides_tenant_wide_unknowns_from_property_memberships(): void
    {
        [$tenant, $property, $user, $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create();
        $connection = $this->connection();
        $import = SettlementReportImport::query()->create([
            'integration_connection_id' => $connection->id,
            'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-1',
            'report_type' => 'account_money', 'provider_report_id' => 'finance-exception-queue', 'revision' => 1,
            'file_name' => 'finance-exception-queue.csv', 'file_checksum' => hash('sha256', 'finance-exception-queue'),
            'report_metadata' => ['fixture' => true], 'is_fixture' => true, 'row_count' => 3, 'imported_at' => now(),
        ]);
        $row = [
            'settlement_report_import_id' => $import->id,
            'row_identity' => hash('sha256', 'allowed'), 'occurrence' => 1, 'source_id' => 'safe-source',
            'external_reference' => 'safe-reference', 'currency' => 'ARS', 'row_kind' => 'SETTLEMENT',
            'application_state' => 'mismatched', 'canonical_checksum' => hash('sha256', 'safe-row'),
            'canonical_row' => ['SOURCE_ID' => 'safe-source', 'TRANSACTION_AMOUNT' => '101.00'], 'recorded_at' => now(),
        ];
        $allowed = SettlementReportRow::query()->create([...$row, 'property_id' => $property->id]);
        $other = SettlementReportRow::query()->create([
            ...$row, 'property_id' => $otherProperty->id, 'row_identity' => hash('sha256', 'other'),
            'canonical_checksum' => hash('sha256', 'other-row'),
        ]);
        $unknown = SettlementReportRow::query()->create([
            ...$row, 'property_id' => null, 'row_identity' => hash('sha256', 'unknown'),
            'canonical_checksum' => hash('sha256', 'unknown-row'), 'application_state' => 'unmatched',
        ]);

        $this->assertTrue(SettlementReportRowResource::canViewAny());
        $this->assertSame([$allowed->id], SettlementReportRowResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(Gate::allows('view', $allowed));
        $this->assertSame('101.00', $allowed->canonical_row['TRANSACTION_AMOUNT']);
        $this->assertSame('seller-1', $allowed->settlementReportImport->provider_account);
        $this->assertFalse(Gate::allows('view', $other));
        $this->assertFalse(Gate::allows('view', $unknown));

        $membership->update(['property_id' => null]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $this->assertEqualsCanonicalizing([$allowed->id, $other->id, $unknown->id], SettlementReportRowResource::getEloquentQuery()->pluck('id')->all());
        $this->assertTrue(Gate::allows('view', $unknown));

        $membership->update(['role' => MembershipRole::Viewer]);
        app(TenantContext::class)->set($tenant, $membership->fresh());
        $this->assertFalse(SettlementReportRowResource::canViewAny());
        $this->assertSame($user->id, auth()->id());
    }

    public function test_real_webhook_http_rejects_missing_bad_and_stale_signatures_accepts_unknown_resource_and_throttles(): void
    {
        $this->tenantEnvironment(MembershipRole::Finance);
        Queue::fake();
        putenv('MP_HTTP_WEBHOOK_SECRET=provider-http-test-secret');
        $key = str_repeat('s', 48);
        IntegrationConnection::query()->create([
            'name' => 'signed-webhook-http', 'type' => 'payment',
            'configuration' => [
                'provider' => 'mercado_pago', 'environment' => 'sandbox', 'provider_account' => 'seller-http',
                'webhook_key' => $key, 'webhook_secret_reference' => 'env:MP_HTTP_WEBHOOK_SECRET',
                'transport' => 'deterministic_fixture', 'fixture' => [],
            ],
            'secret_reference' => 'env:UNUSED_DETERMINISTIC_TOKEN',
        ]);
        $body = json_encode(['type' => 'payment', 'action' => 'payment.updated', 'data' => ['id' => 'unknown-resource']], JSON_THROW_ON_ERROR);
        $url = "/api/v1/payment-webhooks/{$key}?data.id=unknown-resource";
        $base = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUEST_ID' => 'signed-http-1'];

        $this->call('POST', $url, [], [], [], $base, $body)->assertUnauthorized();
        $this->call('POST', $url, [], [], [], $base + ['HTTP_X_SIGNATURE' => 'ts='.(now()->getTimestampMs()).',v1=bad'], $body)->assertUnauthorized();
        $stale = now()->subMinutes(10)->getTimestampMs();
        $this->call('POST', $url, [], [], [], $base + ['HTTP_X_SIGNATURE' => $this->signature('unknown-resource', 'signed-http-1', $stale)], $body)->assertUnauthorized();
        $timestamp = now()->getTimestampMs();
        $this->call('POST', $url, [], [], [], $base + ['HTTP_X_SIGNATURE' => $this->signature('unknown-resource', 'signed-http-1', $timestamp)], $body)->assertOk();
        $event = ProviderEvent::query()->sole();
        try {
            app(ProcessProviderEvent::class)->handle($event);
            $this->fail('Unknown provider resources must not be applied.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $event->fresh()->processing_state->value);
            $this->assertDatabaseCount('payments', 0);
        }

        $ip = '10.99.0.77';
        RateLimiter::clear('payment-webhook:'.$ip);
        $throttleServer = $base + ['REMOTE_ADDR' => $ip];
        for ($request = 0; $request < 240; $request++) {
            $this->call('POST', $url, [], [], [], $throttleServer, $body)->assertUnauthorized();
        }
        $this->call('POST', $url, [], [], [], $throttleServer, $body)->assertTooManyRequests();
        putenv('MP_HTTP_WEBHOOK_SECRET');
    }

    public function test_closure_migration_round_trip_backfills_existing_provider_identity_and_restores_legacy_schema(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Upgrade/rollback DDL proof runs on the production PostgreSQL engine.');
        }
        [, $property] = $this->tenantEnvironment(MembershipRole::Finance);
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [, $attempt, $payment] = $this->approvedPayment($property->id, $fake, 'migration-payment', 10_000);
        $migration = require database_path('migrations/2026_08_20_000100_close_payment_provider_lifecycle.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('payments', 'environment'));
        $this->assertFalse(Schema::hasColumn('integration_connections', 'payment_webhook_key'));
        $this->assertFalse(Schema::hasTable('settlement_entry_revisions'));
        $this->assertSame(0, DB::table('settlement_entries')->where('source_id', 'migration-payment')->value('tax_minor'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('payments', 'environment'));
        $this->assertTrue(Schema::hasColumn('integration_connections', 'payment_webhook_key'));
        $this->assertTrue(Schema::hasTable('settlement_report_imports'));
        $this->assertSame($attempt->environment, DB::table('payments')->where('id', $payment->id)->value('environment'));
        $this->assertSame($attempt->provider_account, DB::table('payments')->where('id', $payment->id)->value('provider_account'));
        $this->assertSame($attempt->environment, DB::table('settlement_entries')->where('source_id', 'migration-payment')->value('environment'));
        $expectedEndpointIdentity = Schema::hasTable('integration_endpoint_keys')
            ? hash('sha256', str_repeat('w', 48))
            : str_repeat('w', 48);
        $this->assertSame($expectedEndpointIdentity, DB::table('integration_connections')->where('id', $attempt->integration_connection_id)->value('payment_webhook_key'));
    }

    public function test_property_scoped_finance_cannot_reconcile_or_recover_other_property_records(): void
    {
        [, $allowedProperty] = $this->tenantEnvironment(MembershipRole::Finance);
        $otherProperty = Property::factory()->create();
        $fake = new FakePaymentGateway;
        $this->app->instance(PaymentGatewayFactory::class, $fake);
        [$reservation, $attempt, $payment] = $this->approvedPayment($otherProperty->id, $fake, 'cross-property-payment', 20_000);
        $request = $attempt->paymentRequest;
        app(FolioService::class)->append($reservation, FolioLineType::Adjustment, 'Cross-property cancellation credit', 1000, -5_000, auth()->id());
        $refundRequest = app(RequestRefund::class)->handle($reservation, $payment, 5_000, 'Cross-property recovery probe', auth()->id());
        $providerRefund = ProviderRefund::query()->create([
            'property_id' => $otherProperty->id,
            'payment_id' => $payment->id,
            'reservation_change_id' => $refundRequest->id,
            'integration_connection_id' => $attempt->integration_connection_id,
            'provider' => $attempt->provider,
            'environment' => $attempt->environment,
            'provider_account' => $attempt->provider_account,
            'source_amount_minor' => 5_000,
            'source_currency' => 'ARS',
            'charge_amount_minor' => 5_000,
            'charge_currency' => 'ARS',
            'idempotency_key' => fake()->uuid(),
            'provider_payment_id' => $payment->provider_reference,
            'state' => 'processing',
        ]);
        $fake->disputes['cross-property-dispute'] = new ProviderDispute(
            'cross-property-dispute', $payment->provider_reference, 'charged_back', 'in_process', 20_000, 'ARS',
            $attempt->provider_account, 'general', null, true, CarbonImmutable::now()->addDay(), CarbonImmutable::now()->subDay(), CarbonImmutable::now(),
        );
        app(ProcessProviderEvent::class)->handle($this->event($attempt, 'cross-property-dispute-event', 'cross-property-dispute', 'topic_chargebacks_wh'));
        $dispute = $attempt->providerDisputes()->sole();
        $settlement = $attempt->integrationConnection->settlementEntries()->sole();

        $this->withHeader('X-Tenant-ID', $reservation->tenant_id)
            ->getJson("/api/v1/reservations/{$reservation->id}/payment-requests")
            ->assertForbidden();
        $this->withHeader('X-Tenant-ID', $reservation->tenant_id)
            ->postJson("/api/v1/payment-attempts/{$attempt->id}/reconcile", [], ['Idempotency-Key' => 'cross-property-reconcile-1'])
            ->assertForbidden();
        $this->withHeader('X-Tenant-ID', $reservation->tenant_id)
            ->postJson("/api/v1/provider-refunds/{$providerRefund->id}/recover", ['provider_refund_id' => 'blocked'], ['Idempotency-Key' => 'cross-property-refund-1'])
            ->assertForbidden();
        $this->withHeader('X-Tenant-ID', $reservation->tenant_id)
            ->postJson("/api/v1/provider-disputes/{$dispute->id}/resolve", ['notes' => 'blocked'], ['Idempotency-Key' => 'cross-property-dispute-1'])
            ->assertForbidden();
        $this->withHeader('X-Tenant-ID', $reservation->tenant_id)
            ->postJson("/api/v1/settlement-entries/{$settlement->id}/variance", ['action' => 'investigate', 'notes' => 'blocked'], ['Idempotency-Key' => 'cross-property-settlement-1'])
            ->assertForbidden();
        $this->assertNotSame($allowedProperty->id, $otherProperty->id);
    }

    /** @return array{Reservation, PaymentAttempt, Payment} */
    private function approvedPayment(string $propertyId, FakePaymentGateway $fake, string $providerId, int $amount, array $settlement = []): array
    {
        Queue::fake();
        $reservation = $this->reservation($propertyId, $amount);
        $connection = $this->connection();
        $request = app(IssuePaymentRequest::class)->handle($reservation, PaymentRequestPurpose::FullOutstanding, null, null, auth()->id())->request;
        $attempt = app(CreateProviderCheckout::class)->handle($request, $connection);
        $fake->payments[$providerId] = new ProviderPayment(
            $providerId, $attempt->external_reference, 'approved', 'accredited', $amount, 'ARS', 'seller-1', $settlement,
        );
        app(ProcessProviderEvent::class)->handle($this->event($attempt, 'approved-'.$providerId, $providerId));

        return [$reservation, $attempt->fresh(), $request->fresh()->payment];
    }

    private function event(PaymentAttempt $attempt, string $delivery, string $resource, string $topic = 'payment'): ProviderEvent
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

    private function reservation(string $propertyId, int $amount): Reservation
    {
        return Reservation::factory()->create([
            'property_id' => $propertyId,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'ARS',
            'subtotal_minor' => $amount,
            'tax_minor' => 0,
            'total_minor' => $amount,
        ]);
    }

    private function connection(): IntegrationConnection
    {
        return IntegrationConnection::query()->create([
            'name' => 'mercado-pago-argentina',
            'type' => 'payment',
            'configuration' => [
                'provider' => 'mercado_pago',
                'environment' => 'sandbox',
                'provider_account' => 'seller-1',
                'return_url_base' => 'https://inn.test',
                'webhook_key' => str_repeat('w', 48),
            ],
            'secret_reference' => 'env:MP_TEST_TOKEN',
        ]);
    }

    private function signature(string $resourceId, string $requestId, int $timestamp): string
    {
        $manifest = 'id:'.strtolower($resourceId).';request-id:'.$requestId.';ts:'.$timestamp.';';

        return 'ts='.$timestamp.',v1='.hash_hmac('sha256', $manifest, 'provider-http-test-secret');
    }
}
