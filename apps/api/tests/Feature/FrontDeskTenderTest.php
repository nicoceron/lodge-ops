<?php

namespace Tests\Feature;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Enums\MembershipRole;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEntryMode;
use App\Enums\PaymentOrigin;
use App\Models\Audit;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\FinancialCommandRecord;
use App\Models\GeneratedDocument;
use App\Models\GuestPaymentEvidence;
use App\Models\Membership;
use App\Models\Outbox;
use App\Models\Payment;
use App\Models\PaymentTenderDetail;
use App\Models\Property;
use App\Models\ReportExport;
use App\Models\Reservation;
use App\Models\ReservationChange;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\ApproveCashVariance;
use App\Services\Payments\CloseCashShift;
use App\Services\Payments\OpenCashShift;
use App\Services\Payments\RecordCashMovement;
use App\Services\Payments\RecordFrontDeskPayment;
use App\Services\Payments\SensitivePaymentDataGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenant;
use Tests\TestCase;

class FrontDeskTenderTest extends TestCase
{
    use CreatesTenant, RefreshDatabase;

    public function test_cash_shift_payment_and_variance_are_derived_and_exactly_once(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Operations);
        $property->update(['settings' => ['cash_variance_threshold_minor_by_currency' => ['COP' => 0]]]);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id,
            'currency' => 'COP',
            'subtotal_minor' => 10_000,
            'tax_minor' => 0,
            'total_minor' => 10_000,
        ]);
        $shift = app(OpenCashShift::class)->handle($user, $property->id, 'cop', 1_000, 'open-shift-00000001');
        app(RecordCashMovement::class)->handle($user, $shift, CashMovementType::PayIn, 500, 'Petty-cash replenishment', 'cash-pay-in-00000001');
        $input = new FrontDeskPaymentInput($reservation->id, PaymentChannel::Cash, 2_000, 'cash-payment-00000001');
        $first = app(RecordFrontDeskPayment::class)->handle($user, $input);
        $replay = app(RecordFrontDeskPayment::class)->handle($user, $input);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(PaymentChannel::Cash, $first->payment->channel);
        $this->assertSame(PaymentEntryMode::StaffRecorded, $first->payment->entry_mode);
        $this->assertSame(PaymentOrigin::Manual, $first->payment->origin);
        $this->assertSame(3_500, $shift->fresh()->currentExpectedMinor());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('folio_lines', 1);
        $this->assertDatabaseCount('cash_shift_movements', 3);

        $closed = app(CloseCashShift::class)->handle($user, $shift, 3_400, 'Counted COP 100 short', 'close-shift-00000001');
        $this->assertSame(CashShiftState::VarianceReview, $closed->state);
        $this->assertSame(3_500, $closed->expected_cash_minor);
        $this->assertSame(-100, $closed->variance_minor);

        // Manager/Finance approval is separated from the Operations cashier role.
        [, , $manager] = $this->switchRole($property, MembershipRole::Manager);
        $approved = app(ApproveCashVariance::class)->handle($manager, $closed, 'Supervisor accepted documented shortage', 'approve-shift-000001');
        $this->assertSame(CashShiftState::Closed, $approved->state);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_external_terminal_identity_is_required_and_duplicates_are_reviewed_without_posting(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'subtotal_minor' => 30_000, 'tax_minor' => 0, 'total_minor' => 30_000]);
        $missing = app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
            $reservation->id, PaymentChannel::ExternalTerminal, 10_000, 'terminal-missing-0001',
        ));
        $this->assertSame('identity_exception', $missing->state);
        $this->assertNull($missing->payment_id);
        $this->assertDatabaseCount('payments', 0);

        $posted = app(RecordFrontDeskPayment::class)->handle($user, $this->terminalInput($reservation, 'terminal-posted-0001'));
        $this->assertSame('posted', $posted->state);
        $this->assertSame('processor-one', $posted->processor_alias);
        $this->assertSame('approved-ref-100', $posted->transaction_reference);
        $this->assertDatabaseCount('payments', 1);

        $otherReservation = Reservation::factory()->create(['property_id' => $property->id, 'subtotal_minor' => 30_000, 'tax_minor' => 0, 'total_minor' => 30_000]);
        $duplicate = app(RecordFrontDeskPayment::class)->handle($user, $this->terminalInput($otherReservation, 'terminal-duplicate-01'));
        $this->assertSame('duplicate_review', $duplicate->state);
        $this->assertSame($posted->id, $duplicate->duplicate_of_id);
        $this->assertNull($duplicate->payment_id);
        $this->assertDatabaseCount('payments', 1);

        $differentTerminal = app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
            reservationId: $otherReservation->id,
            channel: PaymentChannel::ExternalTerminal,
            amountMinor: 5_000,
            idempotencyKey: 'terminal-different-01',
            processorAlias: 'Processor One',
            merchantAccountAlias: 'Front Desk Merchant',
            terminalIdentifier: 'Terminal 02',
            transactionReference: 'Approved Ref 100',
        ));
        $this->assertSame('posted', $differentTerminal->state);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_pan_like_and_changed_idempotency_payloads_are_rejected(): void
    {
        [, $property, $user] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'subtotal_minor' => 30_000, 'tax_minor' => 0, 'total_minor' => 30_000]);

        try {
            app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput(
                reservationId: $reservation->id,
                channel: PaymentChannel::ExternalTerminal,
                amountMinor: 5_000,
                idempotencyKey: 'terminal-pan-guard-001',
                processorAlias: 'Processor One',
                merchantAccountAlias: 'Merchant',
                terminalIdentifier: 'POS-1',
                transactionReference: '4111 1111 1111 1111',
            ));
            $this->fail('PAN-like content must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transaction_reference', $exception->errors());
        }

        $first = app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput($reservation->id, PaymentChannel::BankTransfer, 1_000, 'same-command-key-0001', transactionReference: 'transfer-a'));
        $this->assertNotNull($first->payment_id);
        $this->expectException(ValidationException::class);
        app(RecordFrontDeskPayment::class)->handle($user, new FrontDeskPaymentInput($reservation->id, PaymentChannel::BankTransfer, 2_000, 'same-command-key-0001', transactionReference: 'transfer-a'));
    }

    public function test_justified_luhn_false_positive_resolution_is_role_limited_audited_and_replay_safe(): void
    {
        foreach ([MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Finance] as $role) {
            [, $property, $actor] = $this->tenantEnvironment($role);
            $reservation = Reservation::factory()->create([
                'property_id' => $property->id, 'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000,
            ]);
            $key = 'luhn-resolution-'.$role->value;
            $justification = 'Matched all three values to the standalone terminal receipt and confirmed they are reference identifiers.';
            $input = new FrontDeskPaymentInput(
                reservationId: $reservation->id,
                channel: PaymentChannel::ExternalTerminal,
                amountMinor: 1_000,
                idempotencyKey: $key,
                processorAlias: 'Processor One',
                merchantAccountAlias: 'Front Desk Merchant',
                terminalIdentifier: 'Terminal 01',
                transactionReference: '1234567890128',
                authorizationReference: '1234567890128',
                batchReference: '1234567890128',
                luhnFalsePositiveFields: ['transaction_reference', 'authorization_reference', 'batch_reference'],
                luhnFalsePositiveJustification: $justification,
            );

            $detail = app(RecordFrontDeskPayment::class)->handle($actor, $input);
            $replay = app(RecordFrontDeskPayment::class)->handle($actor, $input);
            $this->assertSame($detail->id, $replay->id);
            $this->assertSame('1234567890128', $detail->transaction_reference);
            $this->assertSame('1234567890128', $detail->authorization_reference);
            $this->assertSame('1234567890128', $detail->batch_reference);
            $audit = Audit::query()->where('event', 'luhn_false_positive_resolved')->where('auditable_id', $detail->id)->sole();
            $this->assertSame($actor->id, $audit->actor_id);
            $this->assertSame($justification, data_get($audit->new_values, 'justification'));
            $this->assertSame(['authorization_reference', 'batch_reference', 'transaction_reference'], data_get($audit->new_values, 'fields'));
            $this->assertSame(hash('sha256', '1234567890128'), data_get($audit->new_values, 'reference_hashes.transaction_reference'));
            $this->assertSame(hash('sha256', '1234567890128'), data_get($audit->new_values, 'reference_hashes.authorization_reference'));
            $this->assertSame(hash('sha256', '1234567890128'), data_get($audit->new_values, 'reference_hashes.batch_reference'));
            $this->assertSame(1, Audit::query()->where('event', 'luhn_false_positive_resolved')->where('auditable_id', $detail->id)->count());

            try {
                app(RecordFrontDeskPayment::class)->handle($actor, new FrontDeskPaymentInput(
                    reservationId: $reservation->id,
                    channel: PaymentChannel::ExternalTerminal,
                    amountMinor: 1_000,
                    idempotencyKey: $key,
                    processorAlias: 'Processor One',
                    merchantAccountAlias: 'Front Desk Merchant',
                    terminalIdentifier: 'Terminal 01',
                    transactionReference: '1234567890128',
                    authorizationReference: '1234567890128',
                    batchReference: '1234567890128',
                    luhnFalsePositiveFields: ['transaction_reference', 'authorization_reference', 'batch_reference'],
                    luhnFalsePositiveJustification: $justification.' Changed on replay.',
                ));
                $this->fail('A changed justification must not replay the original financial command.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $deniedRoles = array_filter(MembershipRole::cases(), fn (MembershipRole $role): bool => ! in_array($role, [
            MembershipRole::Administrator, MembershipRole::Manager, MembershipRole::Finance,
        ], true));
        foreach ($deniedRoles as $role) {
            [, $property, $actor] = $this->tenantEnvironment($role);
            $reservation = Reservation::factory()->create([
                'property_id' => $property->id, 'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000,
            ]);
            try {
                app(RecordFrontDeskPayment::class)->handle($actor, new FrontDeskPaymentInput(
                    reservationId: $reservation->id,
                    channel: PaymentChannel::BankTransfer,
                    amountMinor: 1_000,
                    idempotencyKey: 'luhn-role-denied-'.$role->value,
                    transactionReference: '1234567890128',
                    luhnFalsePositiveFields: ['transaction_reference'],
                    luhnFalsePositiveJustification: 'Reviewed the printed receipt but this role cannot resolve the detector exception.',
                ));
                $this->fail("{$role->value} must not resolve a Luhn false positive.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('authorization', $exception->errors());
            }
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_luhn_resolution_never_overrides_other_fields_sensitive_authentication_data_or_model_guard(): void
    {
        [, $property, $finance] = $this->tenantEnvironment(MembershipRole::Finance);
        $reservation = Reservation::factory()->create([
            'property_id' => $property->id, 'subtotal_minor' => 20_000, 'tax_minor' => 0, 'total_minor' => 20_000,
        ]);

        try {
            app(SensitivePaymentDataGuard::class)->assertSafe(
                ['note' => '1234567890128'],
                '',
                ['note'],
            );
            $this->fail('Only the three reference fields may be resolved as Luhn false positives.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('luhn_false_positive_fields', $exception->errors());
        }

        foreach ([
            'CVV 123',
            'expiry 12/29',
            'PIN 1234',
            ';4111111111111111=29121010000000000000',
        ] as $index => $sensitive) {
            try {
                app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
                    reservationId: $reservation->id,
                    channel: PaymentChannel::ExternalTerminal,
                    amountMinor: 1_000,
                    idempotencyKey: 'luhn-sad-denied-'.$index,
                    processorAlias: 'Processor One',
                    merchantAccountAlias: 'Merchant',
                    terminalIdentifier: 'Terminal 01',
                    transactionReference: $sensitive,
                    luhnFalsePositiveFields: ['transaction_reference'],
                    luhnFalsePositiveJustification: 'This attempted resolution must remain subordinate to sensitive authentication data detection.',
                ));
                $this->fail('Sensitive authentication data must never be overridable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('transaction_reference', $exception->errors());
            }
        }

        try {
            app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
                reservationId: $reservation->id,
                channel: PaymentChannel::ExternalTerminal,
                amountMinor: 1_000,
                idempotencyKey: 'luhn-other-field-denied',
                processorAlias: '1234567890128',
                merchantAccountAlias: 'Merchant',
                terminalIdentifier: 'Terminal 01',
                transactionReference: 'receipt-safe-1',
                luhnFalsePositiveFields: ['transaction_reference'],
                luhnFalsePositiveJustification: 'Only the selected transaction reference was reviewed as a detector false positive.',
            ));
            $this->fail('A resolution must not apply to another field.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('processor_alias', $exception->errors());
        }

        foreach ([null, 'Too short'] as $index => $justification) {
            try {
                app(RecordFrontDeskPayment::class)->handle($finance, new FrontDeskPaymentInput(
                    reservationId: $reservation->id,
                    channel: PaymentChannel::BankTransfer,
                    amountMinor: 1_000,
                    idempotencyKey: 'luhn-justification-denied-'.$index,
                    transactionReference: '1234567890128',
                    luhnFalsePositiveFields: ['transaction_reference'],
                    luhnFalsePositiveJustification: $justification,
                ));
                $this->fail('A documented justification is mandatory.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('luhn_false_positive_justification', $exception->errors());
            }
        }

        try {
            (new PaymentTenderDetail(['transaction_reference' => '1234567890128']))->save();
            $this->fail('The model-level guard must reject an unscoped Luhn value.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('PaymentTenderDetail.transaction_reference', $exception->errors());
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_tender_details', 0);
        $this->assertSame(0, Audit::query()->where('event', 'luhn_false_positive_resolved')->count());
    }

    public function test_sensitive_card_data_is_rejected_centrally_from_operational_outbox_document_and_report_storage(): void
    {
        $this->tenantEnvironment(MembershipRole::Finance);
        app(SensitivePaymentDataGuard::class)->assertSafe(['phone' => '+4477009000007']);
        app(SensitivePaymentDataGuard::class)->assertSafe(['deduplication_key' => hash('sha256', 'safe-machine-key')]);
        app(SensitivePaymentDataGuard::class)->assertSafe([
            'storage_path' => 'guest-payment-evidence/00000000-0000-4000-8000-000000abcd05/20260000-0000-4000-8000-000000000000/receipt.pdf',
        ]);
        app(SensitivePaymentDataGuard::class)->assertSafe([
            'price_snapshot' => json_encode([
                'quote_id' => '00000000-0000-4000-8000-000000abcd05',
                'checksum' => str_repeat('4111', 16),
            ], JSON_THROW_ON_ERROR),
        ]);
        $this->addToAssertionCount(4);
        try {
            app(SensitivePaymentDataGuard::class)->assertSafe([
                'price_snapshot' => json_encode(['guest_note' => '4111 1111 1111 1111'], JSON_THROW_ON_ERROR),
            ]);
            $this->fail('Nested JSON guest content must still be scanned for PAN data.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload.price_snapshot.guest_note', $exception->errors());
        }
        try {
            app(SensitivePaymentDataGuard::class)->assertSafe(['deduplication_key' => '1234567890128']);
            $this->fail('Only a complete SHA-256 digest may bypass PAN detection for a deduplication key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload.deduplication_key', $exception->errors());
        }
        $unsafe = 'Guest supplied PAN 4111 1111 1111 1111 and CVV 123';
        $ingresses = [
            [new ReservationChange(['metadata' => ['refund_reason' => $unsafe]]), 'reservation_changes'],
            [new CashShiftMovement(['reason' => $unsafe]), 'cash_shift_movements'],
            [new GuestPaymentEvidence(['original_name' => $unsafe.'.pdf']), 'guest_payment_evidence'],
            [new PaymentTenderDetail(['transaction_reference' => $unsafe]), 'payment_tender_details'],
            [new FinancialCommandRecord(['idempotency_key' => $unsafe]), 'financial_command_records'],
            [new Outbox(['payload' => ['reason' => $unsafe]]), 'outbox'],
            [new GeneratedDocument(['metadata' => ['execution_reference' => $unsafe]]), 'generated_documents'],
            [new ReportExport(['filters' => ['transfer_reference' => $unsafe]]), 'report_exports'],
        ];

        foreach ($ingresses as [$model, $table]) {
            try {
                $model->save();
                $this->fail("Sensitive card data reached {$table}.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_sales_is_explicitly_denied_every_payment_mutation(): void
    {
        [, $property, $sales] = $this->tenantEnvironment(MembershipRole::Sales);
        $reservation = Reservation::factory()->create(['property_id' => $property->id, 'subtotal_minor' => 10_000, 'tax_minor' => 0, 'total_minor' => 10_000]);
        $payment = new Payment(['reservation_id' => $reservation->id]);
        $payment->tenant_id = $reservation->tenant_id;
        $shift = new CashShift(['property_id' => $property->id]);
        $shift->tenant_id = $reservation->tenant_id;
        $evidence = new GuestPaymentEvidence(['reservation_id' => $reservation->id]);
        $evidence->tenant_id = $reservation->tenant_id;

        $this->assertFalse($sales->can('create', Payment::class), 'Sales record-tender decision');
        $this->assertFalse($sales->can('create', CashShift::class), 'Sales open-shift decision');
        $this->assertFalse($sales->can('operate', $shift), 'Sales cash-operation decision');
        $this->assertFalse($sales->can('approveVariance', $shift), 'Sales variance-approval decision');
        $this->assertFalse($sales->can('reconcile', $payment), 'Sales reconciliation decision');
        $this->assertFalse($sales->can('reverse', $payment), 'Sales refund/reversal decision');
        $this->assertFalse($sales->can('review', $evidence), 'Sales evidence-review decision');
        $this->assertFalse($sales->can('download', $evidence), 'Sales private-evidence decision');
        $this->assertFalse($sales->can('resolve', new PaymentTenderDetail(['property_id' => $property->id])), 'Sales duplicate-resolution decision');
        $this->expectException(ValidationException::class);
        app(RecordFrontDeskPayment::class)->handle($sales, new FrontDeskPaymentInput($reservation->id, PaymentChannel::BankTransfer, 1_000, 'sales-denied-command-01'));
    }

    private function terminalInput(Reservation $reservation, string $key): FrontDeskPaymentInput
    {
        return new FrontDeskPaymentInput(
            reservationId: $reservation->id,
            channel: PaymentChannel::ExternalTerminal,
            amountMinor: 10_000,
            idempotencyKey: $key,
            processorAlias: 'Processor One',
            merchantAccountAlias: 'Front Desk Merchant',
            terminalIdentifier: 'Terminal 01',
            transactionReference: 'Approved Ref 100',
            authorizationReference: 'Auth 200',
            batchReference: 'Batch 300',
            cardBrand: 'Visa',
            cardLastFour: '4242',
        );
    }

    /** @return array{Tenant, Property, User, Membership} */
    private function switchRole(Property $property, MembershipRole $role): array
    {
        $tenant = $property->tenant;
        $user = User::factory()->create();
        $membership = Membership::factory()->create(['user_id' => $user->id, 'property_id' => $property->id, 'role' => $role]);
        app(TenantContext::class)->set($tenant, $membership);

        return [$tenant, $property, $user, $membership];
    }
}
