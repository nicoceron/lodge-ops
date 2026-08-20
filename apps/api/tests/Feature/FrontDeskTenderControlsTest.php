<?php

namespace Tests\Feature;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEvidenceStatus;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\GuestPaymentEvidence;
use App\Models\Reservation;
use App\Services\PaymentEvidenceScanner;
use App\Services\Payments\CompleteManualExternalRefund;
use App\Services\Payments\CorrectRemainingReversibleAmount;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\RecordFrontDeskPayment;
use App\Services\Payments\RequestManualExternalRefund;
use App\Services\Payments\ResolveTenderDuplicate;
use App\Services\Payments\ReviewRefundEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}
