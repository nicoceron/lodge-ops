<?php

namespace Tests\Feature;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEvidenceStatus;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\IdempotencyKey;
use App\Models\Membership;
use App\Models\Reservation;
use App\Models\User;
use App\Services\PaymentEvidenceScanner;
use App\Services\Payments\CloseCashShift;
use App\Services\Payments\CompleteManualExternalRefund;
use App\Services\Payments\CorrectRemainingReversibleAmount;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\RecordCashMovement;
use App\Services\Payments\RecordFrontDeskPayment;
use App\Services\Payments\RequestManualExternalRefund;
use App\Services\Payments\ResolveTenderDuplicate;
use App\Services\Payments\ReviewRefundEvidence;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FrontDeskTenderControlsTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_corrected_terminal_identity_retries_the_typed_command_without_posting_the_draft(): void
    {
        [, $property, $finance] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = $this->reservation($property->id, 12_000);
        $draft = app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::ExternalTerminal, 4_000, 'missing-terminal-identity-001',
        ));

        $retried = app(ResolveTenderDuplicate::class)->handle(
            $finance,
            $draft,
            'corrected_identity',
            'Finance verified the printed receipt identity.',
            'resolve-terminal-draft-001',
            new FrontDeskPaymentInput(
                reservationId: $reservation->id,
                channel: PaymentChannel::ExternalTerminal,
                amountMinor: 4_000,
                idempotencyKey: 'retry-terminal-draft-0001',
                processorAlias: 'Standalone Processor',
                merchantAccountAlias: 'Lodge Front Desk',
                terminalIdentifier: 'Terminal 04',
                transactionReference: 'Receipt 0042',
                cardBrand: 'Test brand',
                cardLastFour: '0042',
            ),
        );

        $this->assertSame('posted', $retried->state);
        $this->assertNotNull($retried->payment_id);
        $this->assertSame('corrected_identity_submitted', $draft->fresh()->state);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
    }

    public function test_non_cash_refund_requires_approved_private_evidence_and_replays_exactly_once(): void
    {
        [, $property, $finance] = $this->tenantEnvironment(MembershipRole::Finance);
        $guest = Guest::factory()->create();
        $reservation = $this->reservation($property->id, 10_000, $guest->id);
        $detail = app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::BankTransfer, 10_000, 'bank-payment-refund-0001', transactionReference: 'wire-safe-100',
        ));
        $reservation->update(['subtotal_minor' => 6_000, 'total_minor' => 6_000]);
        $request = app(RequestManualExternalRefund::class)->handle($finance, $detail->payment, 4_000, 'Guest overpayment', 'bank-refund-request-0001');
        $evidence = $this->refundEvidence($reservation, $guest, $request->id);

        try {
            app(CompleteManualExternalRefund::class)->handle($finance, $request, 'external-refund-100', 'bank-refund-complete-001', $evidence);
            $this->fail('Unapproved refund evidence must not complete money movement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence_id', $exception->errors());
        }

        app(ReviewRefundEvidence::class)->handle($finance, $evidence, 'approved', 'Matched the external bank refund receipt.', 'refund-evidence-review-01');
        $completed = app(CompleteManualExternalRefund::class)->handle($finance, $request, 'external-refund-100', 'bank-refund-complete-001', $evidence);
        $replay = app(CompleteManualExternalRefund::class)->handle($finance, $request, 'external-refund-100', 'bank-refund-complete-001', $evidence);

        $this->assertSame($completed->id, $replay->id);
        $this->assertSame('completed', $completed->status);
        $this->assertSame($completed->id, $evidence->fresh()->refund_change_id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count());
    }

    public function test_cash_refund_requires_the_finance_actors_matching_open_shift_and_posts_negative_movement(): void
    {
        [, $property, $admin] = $this->tenantEnvironment(MembershipRole::Administrator);
        $reservation = $this->reservation($property->id, 10_000);
        $shift = app(OpenCashShift::class)->handle($admin, $property->id, 'COP', 0, 'cash-refund-shift-open-01');
        $detail = app(RecordFrontDeskPayment::class)->handle($admin, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::Cash, 10_000, 'cash-payment-refund-0001',
        ));
        $reservation->update(['subtotal_minor' => 7_000, 'total_minor' => 7_000]);
        $request = app(RequestManualExternalRefund::class)->handle($admin, $detail->payment, 3_000, 'Cash overpayment', 'cash-refund-request-0001');

        try {
            app(CompleteManualExternalRefund::class)->handle($admin, $request, 'cash-drawer-slip-1', 'cash-refund-complete-01');
            $this->fail('Cash cannot be marked dispensed without an open shift.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cash_shift_id', $exception->errors());
        }

        $completed = app(CompleteManualExternalRefund::class)->handle($admin, $request, 'cash-drawer-slip-1', 'cash-refund-complete-01', null, $shift);
        $this->assertSame(7_000, $shift->fresh()->currentExpectedMinor());
        $this->assertDatabaseHas('cash_shift_movements', [
            'refund_change_id' => $completed->id,
            'amount_minor' => -3_000,
            'type' => 'refund',
        ]);
    }

    public function test_drawer_corrections_cannot_oppose_financial_or_opening_float_movements(): void
    {
        [, $property, $admin] = $this->tenantEnvironment(MembershipRole::Administrator);
        $reservation = $this->reservation($property->id, 1_000);
        $shift = app(OpenCashShift::class)->handle($admin, $property->id, 'COP', 500, 'restricted-correction-open-01');
        $detail = app(RecordFrontDeskPayment::class)->handle($admin, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::Cash, 1_000, 'restricted-correction-pay-01',
        ));
        $reservation->update(['subtotal_minor' => 500, 'total_minor' => 500]);
        $refund = app(RequestManualExternalRefund::class)->handle($admin, $detail->payment, 500, 'Correct overpayment', 'restricted-correction-refund-request');
        app(CompleteManualExternalRefund::class)->handle($admin, $refund, 'drawer-refund-slip', 'restricted-correction-refund-complete', null, $shift);

        foreach ($shift->fresh()->movements()->whereIn('type', ['opening_float', 'payment', 'refund'])->get() as $index => $movement) {
            try {
                app(RecordCashMovement::class)->handle(
                    $admin,
                    $shift,
                    CashMovementType::Correction,
                    abs($movement->amount_minor),
                    'Attempted drawer correction',
                    'restricted-correction-'.($index + 1),
                    $movement,
                );
                $this->fail("{$movement->type->value} must use its authoritative financial reversal workflow.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reverses_movement_id', $exception->errors());
            }
        }

        $payIn = app(RecordCashMovement::class)->handle($admin, $shift, CashMovementType::PayIn, 200, 'Drawer replenishment', 'allowed-pay-in-01');
        $correction = app(RecordCashMovement::class)->handle($admin, $shift, CashMovementType::Correction, 200, 'Replenishment entered in error', 'allowed-correction-01', $payIn);
        $this->assertSame(-200, $correction->amount_minor);
        $this->assertSame($payIn->id, $correction->reverses_movement_id);
    }

    public function test_remaining_reversible_correction_creates_only_the_available_controlled_request(): void
    {
        [, $property, $finance] = $this->tenantEnvironment(MembershipRole::Finance);
        $guest = Guest::factory()->create();
        $reservation = $this->reservation($property->id, 10_000, $guest->id);
        $detail = app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::BankTransfer, 10_000, 'remaining-bank-payment-01', transactionReference: 'wire-remain-1',
        ));
        $reservation->update(['subtotal_minor' => 4_000, 'total_minor' => 4_000]);
        $partial = app(RequestManualExternalRefund::class)->handle($finance, $detail->payment, 2_000, 'First partial return', 'remaining-first-request-01');
        $evidence = $this->refundEvidence($reservation, $guest, $partial->id);
        app(ReviewRefundEvidence::class)->handle($finance, $evidence, 'approved', 'Verified first partial return.', 'remaining-first-review-01');
        app(CompleteManualExternalRefund::class)->handle($finance, $partial, 'partial-return-1', 'remaining-first-complete-1', $evidence);

        $remaining = app(CorrectRemainingReversibleAmount::class)->handle($finance, $detail->payment, 'Return remaining guest credit', 'remaining-correction-001');
        $replay = app(CorrectRemainingReversibleAmount::class)->handle($finance, $detail->payment, 'Return remaining guest credit', 'remaining-correction-001');

        $this->assertSame($remaining->id, $replay->id);
        $this->assertSame(4_000, $remaining->amount_minor);
        $this->assertSame('requested', $remaining->status);
        $this->assertSame(1, $reservation->folioLines()->where('type', 'refund')->count(), 'Correction requests external execution; it does not fabricate completion.');
    }

    public function test_http_idempotency_recovers_immediately_from_financial_commit_before_response_persistence(): void
    {
        [$tenant, $property, , $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = $this->reservation($property->id, 8_000);
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => 'http-financial-recovery-0001'];
        $payload = [
            'channel' => 'bank_transfer',
            'amount_minor' => 8_000,
            'transaction_reference' => 'wire-recovery-001',
        ];

        $first = $this->withHeaders($headers)->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", $payload)->assertCreated();
        app(TenantContext::class)->set($tenant, $membership);
        IdempotencyKey::query()->where('key', $headers['Idempotency-Key'])->update(['status_code' => null, 'response_body' => null]);
        $recovered = $this->withHeaders($headers)->postJson("/api/v1/reservations/{$reservation->id}/front-desk-payments", $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $recovered->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseCount('financial_command_records', 1);
        app(TenantContext::class)->set($tenant, $membership);
        $this->assertNotNull(IdempotencyKey::query()->where('key', $headers['Idempotency-Key'])->value('response_body'));
    }

    public function test_multipart_idempotency_is_boundary_independent_and_recovers_the_committed_evidence(): void
    {
        Storage::fake('local');
        [$tenant, $property, $finance, $membership] = $this->tenantEnvironment(MembershipRole::Finance);
        $guest = Guest::factory()->create();
        $reservation = $this->reservation($property->id, 10_000, $guest->id);
        $detail = app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::BankTransfer, 10_000, 'multipart-payment-0001', transactionReference: 'wire-multipart-001',
        ));
        $reservation->update(['subtotal_minor' => 6_000, 'total_minor' => 6_000]);
        $refund = app(RequestManualExternalRefund::class)->handle($finance, $detail->payment, 4_000, 'Guest overpayment', 'multipart-refund-0001');
        $key = 'multipart-evidence-recovery-0001';
        $headers = ['X-Tenant-ID' => $tenant->id, 'Idempotency-Key' => $key, 'Accept' => 'application/json'];
        $uri = "/api/v1/manual-refunds/{$refund->id}/evidence";

        $first = $this->withHeaders($headers)->post($uri, [
            'evidence' => UploadedFile::fake()->createWithContent('refund-proof.pdf', $this->validPdf()),
        ])->assertCreated();
        app(TenantContext::class)->set($tenant, $membership);
        IdempotencyKey::query()->where('key', $key)->update(['status_code' => null, 'response_body' => null]);
        $recovered = $this->withHeaders($headers)->post($uri, [
            'evidence' => UploadedFile::fake()->createWithContent('refund-proof.pdf', $this->validPdf()),
        ])->assertCreated();

        $this->assertSame($first->json('data.id'), $recovered->json('data.id'));
        $this->assertDatabaseCount('guest_payment_evidence', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());

        app(TenantContext::class)->set($tenant, $membership);
        $this->withHeaders($headers)->post($uri, [
            'evidence' => UploadedFile::fake()->createWithContent('refund-proof.pdf', $this->validPdf()."\nchanged"),
        ])->assertConflict();
    }

    public function test_shift_business_date_uses_property_timezone_while_audit_instant_stays_utc(): void
    {
        [, $property, $operations] = $this->tenantEnvironment(MembershipRole::Operations);
        $property->update(['timezone' => 'America/New_York']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01T04:30:00Z'));
        try {
            $beforeFallback = app(OpenCashShift::class)->handle($operations, $property->id, 'USD', 0, 'dst-fallback-shift-0001');
            $this->assertSame('2026-11-01', $beforeFallback->business_date->toDateString());
            $this->assertSame('2026-11-01T04:30:00+00:00', $beforeFallback->opened_at->utc()->toIso8601String());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_force_close_requires_a_reason_and_variance_thresholds_are_currency_specific(): void
    {
        [$tenant, $property, $manager, $managerMembership] = $this->tenantEnvironment(MembershipRole::Manager);
        $cashier = User::factory()->create();
        $cashierMembership = Membership::factory()->create([
            'user_id' => $cashier->id,
            'property_id' => $property->id,
            'role' => MembershipRole::Operations,
        ]);
        app(TenantContext::class)->set($tenant, $cashierMembership);
        $otherShift = app(OpenCashShift::class)->handle($cashier, $property->id, 'USD', 1_000, 'force-close-open-0001');
        $cashierMembership->update(['is_active' => false]);
        app(TenantContext::class)->set($tenant, $managerMembership);

        try {
            app(CloseCashShift::class)->handle($manager, $otherShift, 1_000, null, 'force-close-no-reason-01', true);
            $this->fail('A force-close without an operational reason must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        $forced = app(CloseCashShift::class)->handle($manager, $otherShift, 1_000, 'Cashier account disabled after handoff.', 'force-close-with-reason-1', true);
        $this->assertSame(CashShiftState::Closed, $forced->state);

        $property->update(['settings' => ['cash_variance_threshold_minor_by_currency' => ['USD' => 100, 'COP' => 0]]]);
        $usd = app(OpenCashShift::class)->handle($manager, $property->id, 'USD', 1_000, 'currency-threshold-usd-open');
        $this->assertSame(
            CashShiftState::Closed,
            app(CloseCashShift::class)->handle($manager, $usd, 950, 'USD count is within tolerance.', 'currency-threshold-usd-close')->state,
        );
        $cop = app(OpenCashShift::class)->handle($manager, $property->id, 'COP', 1_000, 'currency-threshold-cop-open');
        $this->assertSame(
            CashShiftState::VarianceReview,
            app(CloseCashShift::class)->handle($manager, $cop, 950, 'COP count requires review.', 'currency-threshold-cop-close')->state,
        );
    }

    public function test_evidence_scanner_fails_closed_for_outage_malware_and_polyglot_content(): void
    {
        $scanner = app(PaymentEvidenceScanner::class);
        config()->set('front_desk_tenders.evidence_scanner_available', false);
        $this->expectException(HttpException::class);
        $scanner->assertSafe(UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nclean"));
    }

    public function test_evidence_scanner_rejects_malware_signature(): void
    {
        $this->expectException(ValidationException::class);
        app(PaymentEvidenceScanner::class)->assertSafe(UploadedFile::fake()->createWithContent(
            'receipt.pdf',
            "%PDF-1.4\nEICAR-STANDARD-ANTIVIRUS-TEST-FILE\n",
        ));
    }

    public function test_evidence_scanner_parses_the_whole_file_and_rejects_late_payload_polyglot_and_malformed_pdf(): void
    {
        $scanner = app(PaymentEvidenceScanner::class);
        $valid = $this->validPdf();
        $scanner->assertSafe(UploadedFile::fake()->createWithContent('valid.pdf', $valid));

        foreach ([
            'late.pdf' => str_replace('trailer', str_repeat('A', 5_000)."<script>alert(1)</script>\ntrailer", $valid),
            'polyglot.pdf' => $valid."<?php echo 'late';",
            'malformed.pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\n%%EOF\n",
        ] as $name => $content) {
            try {
                $scanner->assertSafe(UploadedFile::fake()->createWithContent($name, $content));
                $this->fail("{$name} must fail closed.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('evidence', $exception->errors());
            }
        }
    }

    public function test_evidence_scanner_fails_closed_when_the_real_pdf_parser_is_unavailable(): void
    {
        config()->set('front_desk_tenders.evidence_pdf_parser_binary', '/missing/inn-pdf-parser');

        $this->expectException(HttpException::class);
        app(PaymentEvidenceScanner::class)->assertSafe(UploadedFile::fake()->createWithContent('receipt.pdf', $this->validPdf()));
    }

    private function reservation(string $propertyId, int $totalMinor, ?string $guestId = null): Reservation
    {
        return Reservation::factory()->create([
            'property_id' => $propertyId,
            'primary_guest_id' => $guestId,
            'status' => ReservationStatus::Confirmed,
            'currency' => 'COP',
            'subtotal_minor' => $totalMinor,
            'tax_minor' => 0,
            'total_minor' => $totalMinor,
        ]);
    }

    private function refundEvidence(Reservation $reservation, Guest $guest, string $refundId): GuestPaymentEvidence
    {
        return GuestPaymentEvidence::query()->create([
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'refund_change_id' => $refundId,
            'file_name' => 'refund-receipt.pdf',
            'original_name' => 'refund-receipt.pdf',
            'content_type' => 'application/pdf',
            'detected_mime' => 'application/pdf',
            'size_bytes' => 64,
            'sha256' => hash('sha256', 'synthetic-refund-receipt'),
            'storage_path' => 'payment-evidence/synthetic/refund-receipt.pdf',
            'disk' => 'local',
            'storage_key' => 'payment-evidence/synthetic/refund-receipt.pdf',
            'status' => PaymentEvidenceStatus::Pending,
            'scan_status' => 'accepted',
            'scan_state' => 'accepted',
            'submitted_at' => now(),
            'scanned_at' => now(),
        ]);
    }

    private function validPdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }
}
