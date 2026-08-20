<?php

namespace App\Services\Payments;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\CashMovementType;
use App\Enums\CashShiftState;
use App\Enums\DocumentKind;
use App\Enums\PaymentChannel;
use App\Enums\ReservationStatus;
use App\Models\Audit;
use App\Models\CashShift;
use App\Models\CashShiftMovement;
use App\Models\PaymentTenderDetail;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\CanonicalJson;
use App\Services\Documents\RequestDocumentGeneration;
use App\Services\PaymentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

final class RecordFrontDeskPayment
{
    public function __construct(
        private readonly FinancialCommandExecutor $commands,
        private readonly FrontDeskPaymentGuard $guard,
        private readonly ProhibitedCardData $cardData,
        private readonly CanonicalJson $canonical,
        private readonly PaymentService $payments,
        private readonly RequestDocumentGeneration $documents,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function handle(User $actor, FrontDeskPaymentInput $input): PaymentTenderDetail
    {
        $tenantId = app(TenantContext::class)->tenant()->id;

        /** @var PaymentTenderDetail $result */
        $result = $this->commands->run($tenantId, self::class, $input->idempotencyKey, $input->checksumPayload(), function () use ($actor, $input): PaymentTenderDetail {
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($input->reservationId);
            $this->guard->recordTender($actor, $reservation->property_id);
            if ($input->amountMinor <= 0) {
                throw ValidationException::withMessages(['amount_minor' => 'The payment amount must be greater than zero.']);
            }
            $this->validateInput($actor, $reservation, $input);
            $property = Property::query()->findOrFail($reservation->property_id);
            $aliases = $this->normalizeIdentity($input);
            $receivedAt = now();
            $base = [
                'property_id' => $reservation->property_id,
                'reservation_id' => $reservation->id,
                'deposit_id' => $input->depositId,
                'channel' => $input->channel,
                'amount_minor' => $input->amountMinor,
                'currency' => strtoupper($reservation->currency),
                ...$aliases,
                'authorization_reference' => $this->clean($input->authorizationReference),
                'batch_reference' => $this->clean($input->batchReference),
                'card_brand' => $this->clean($input->cardBrand),
                'card_last_four' => $this->clean($input->cardLastFour),
                'note' => $this->clean($input->note),
                'recorded_by' => $actor->id,
                'received_at' => $receivedAt,
                'business_date' => $receivedAt->copy()->setTimezone($property->timezone)->toDateString(),
                'command_key' => $input->idempotencyKey,
                'command_checksum' => $this->canonical->checksum($input->checksumPayload()),
            ];

            if ($input->channel === PaymentChannel::ExternalTerminal && $this->missingExternalIdentity($aliases)) {
                return $this->createDetail($actor, $input, [
                    ...$base,
                    'state' => 'identity_exception',
                    'review_reason' => 'Processor, merchant/account, terminal, and transaction reference are required before posting.',
                ]);
            }

            if ($input->channel === PaymentChannel::ExternalTerminal) {
                $duplicate = PaymentTenderDetail::query()
                    ->where('property_id', $reservation->property_id)
                    ->where('channel', PaymentChannel::ExternalTerminal)
                    ->where('processor_alias', $aliases['processor_alias'])
                    ->where('merchant_account_alias', $aliases['merchant_account_alias'])
                    ->where('terminal_identifier', $aliases['terminal_identifier'])
                    ->where('transaction_reference', $aliases['transaction_reference'])
                    ->where('state', 'posted')
                    ->lockForUpdate()
                    ->first();
                if ($duplicate !== null) {
                    return $this->createDetail($actor, $input, [
                        ...$base,
                        'state' => 'duplicate_review',
                        'duplicate_of_id' => $duplicate->id,
                        'review_reason' => 'The normalized external-terminal transaction identity is already posted.',
                    ]);
                }
            }

            // Reservation/deposit locks occur in PaymentService before this shift lock.
            $shift = null;
            if ($input->channel === PaymentChannel::Cash) {
                $shift = CashShift::query()->where('property_id', $reservation->property_id)
                    ->where('cashier_id', $actor->id)->where('currency', $reservation->currency)
                    ->where('state', CashShiftState::Open)->lockForUpdate()->first();
                if ($shift === null) {
                    throw ValidationException::withMessages(['cash_shift' => 'Open your cash shift for this property and currency before recording cash.']);
                }
            }

            $payment = $this->payments->recordFrontDesk($reservation, $input->channel, $input->amountMinor, $actor->id, $input->depositId);
            $detail = $this->createDetail($actor, $input, [...$base, 'payment_id' => $payment->id, 'state' => 'posted']);
            if ($shift !== null) {
                CashShiftMovement::query()->create([
                    'property_id' => $reservation->property_id,
                    'cash_shift_id' => $shift->id,
                    'payment_id' => $payment->id,
                    'type' => CashMovementType::Payment,
                    'amount_minor' => $payment->amount_minor,
                    'currency' => $payment->currency,
                    'reason' => 'Front-desk cash payment',
                    'recorded_by' => $actor->id,
                    'occurred_at' => $receivedAt,
                    'command_key' => 'cash-payment:'.$input->idempotencyKey,
                    'command_checksum' => $base['command_checksum'],
                ]);
            }
            $this->outbox->record('payment_tender_detail', $detail->id, 'payment.tender.posted', [
                'tender_detail_id' => $detail->id,
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'channel' => $input->channel->value,
            ]);
            if (in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
                $this->documents->handleSystem($reservation, DocumentKind::PaymentReceipt, app()->getLocale(), 'front-desk-payment-receipt:'.$payment->id, $payment);
            }

            return $detail->fresh(['payment']);
        });

        return $result->loadMissing('payment');
    }

    private function validateInput(User $actor, Reservation $reservation, FrontDeskPaymentInput $input): void
    {
        if (! in_array($input->channel, [PaymentChannel::Cash, PaymentChannel::BankTransfer, PaymentChannel::ExternalTerminal, PaymentChannel::ManualOther], true)) {
            throw ValidationException::withMessages(['channel' => 'Only cash, bank transfer, standalone external terminal, or manual other may be staff-recorded.']);
        }
        if ($input->cardLastFour !== null && ! preg_match('/^\d{4}$/', $input->cardLastFour)) {
            throw ValidationException::withMessages(['card_last_four' => 'Card last four must contain exactly four digits.']);
        }
        if ($input->channel !== PaymentChannel::ExternalTerminal && ($input->cardBrand !== null || $input->cardLastFour !== null)) {
            throw ValidationException::withMessages(['card_last_four' => 'Card brand and last four are only accepted for standalone terminal records.']);
        }
        if ($input->channel === PaymentChannel::ManualOther && trim((string) $input->note) === '') {
            throw ValidationException::withMessages(['note' => 'A bounded explanation is required for manual-other tenders.']);
        }
        foreach (get_object_vars($input) as $name => $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_string($value) && strlen($value) > ($name === 'note' ? 500 : 160)) {
                throw ValidationException::withMessages([$name => 'This tender field is too long.']);
            }
        }
        if ($input->luhnFalsePositiveFields !== []) {
            $this->guard->resolveException($actor, $reservation->property_id);
            $this->cardData->validateLuhnFalsePositiveResolution(
                $input->luhnFalsePositiveFields,
                (string) $input->luhnFalsePositiveJustification,
            );
            foreach ($input->luhnFalsePositiveFields as $field) {
                $property = match ($field) {
                    'transaction_reference' => 'transactionReference',
                    'authorization_reference' => 'authorizationReference',
                    'batch_reference' => 'batchReference',
                    default => null,
                };
                if ($property === null || trim((string) $input->{$property}) === '') {
                    throw ValidationException::withMessages([
                        'luhn_false_positive_fields' => "The resolved {$field} must contain the reviewed reference.",
                    ]);
                }
            }
        } elseif ($input->luhnFalsePositiveJustification !== null) {
            throw ValidationException::withMessages([
                'luhn_false_positive_fields' => 'Select at least one approved reference field for this justification.',
            ]);
        }
        $this->cardData->assertSafe([
            'processor_alias' => $input->processorAlias,
            'merchant_account_alias' => $input->merchantAccountAlias,
            'terminal_identifier' => $input->terminalIdentifier,
            'transaction_reference' => $input->transactionReference,
            'authorization_reference' => $input->authorizationReference,
            'batch_reference' => $input->batchReference,
            'note' => $input->note,
        ], $input->luhnFalsePositiveFields);
    }

    /** @param array<string, mixed> $attributes */
    private function createDetail(User $actor, FrontDeskPaymentInput $input, array $attributes): PaymentTenderDetail
    {
        if ($input->luhnFalsePositiveFields === []) {
            return PaymentTenderDetail::query()->create($attributes);
        }

        return $this->cardData->withLuhnFalsePositiveResolution(
            $input->luhnFalsePositiveFields,
            (string) $input->luhnFalsePositiveJustification,
            function () use ($actor, $input, $attributes): PaymentTenderDetail {
                $detail = PaymentTenderDetail::query()->create($attributes);
                $fields = array_values(array_unique($input->luhnFalsePositiveFields));
                sort($fields);
                $hashes = [];
                foreach ($fields as $field) {
                    $hashes[$field] = hash('sha256', (string) $detail->getAttribute($field));
                }
                Audit::query()->create([
                    'actor_id' => $actor->id,
                    'event' => 'luhn_false_positive_resolved',
                    'auditable_type' => $detail->getMorphClass(),
                    'auditable_id' => $detail->id,
                    'old_values' => null,
                    'new_values' => [
                        'fields' => $fields,
                        'justification' => trim((string) $input->luhnFalsePositiveJustification),
                        'reference_hashes' => $hashes,
                        'command_key' => $input->idempotencyKey,
                    ],
                ]);

                return $detail;
            },
        );
    }

    /** @return array{processor_alias:string, merchant_account_alias:string, terminal_identifier:string, transaction_reference:?string} */
    private function normalizeIdentity(FrontDeskPaymentInput $input): array
    {
        return [
            'processor_alias' => $this->normalize($input->processorAlias) ?? 'manual',
            'merchant_account_alias' => $this->normalize($input->merchantAccountAlias) ?? 'manual',
            'terminal_identifier' => $this->normalize($input->terminalIdentifier) ?? 'manual',
            'transaction_reference' => $this->normalize($input->transactionReference),
        ];
    }

    /** @param array{processor_alias:string, merchant_account_alias:string, terminal_identifier:string, transaction_reference:?string} $identity */
    private function missingExternalIdentity(array $identity): bool
    {
        return in_array('manual', [$identity['processor_alias'], $identity['merchant_account_alias'], $identity['terminal_identifier']], true)
            || $identity['transaction_reference'] === null;
    }

    private function normalize(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        return preg_replace('/[^a-z0-9._:-]+/', '-', $value) ?: null;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
