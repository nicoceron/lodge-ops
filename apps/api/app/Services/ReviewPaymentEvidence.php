<?php

namespace App\Services;

use App\Data\Payments\FrontDeskPaymentInput;
use App\Enums\DepositStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentEvidenceStatus;
use App\Models\Communication;
use App\Models\Deposit;
use App\Models\GuestPaymentEvidence;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Services\Payments\RecordFrontDeskPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviewPaymentEvidence
{
    public function __construct(
        private readonly RecordFrontDeskPayment $payments,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function approve(GuestPaymentEvidence $evidence, ?string $depositId, ?int $actorId, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($evidence, $depositId, $actorId, $note): Payment {
            $locked = GuestPaymentEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($locked->status === PaymentEvidenceStatus::Approved && $locked->payment_id !== null) {
                return Payment::query()->findOrFail($locked->payment_id);
            }
            if ($locked->status === PaymentEvidenceStatus::Rejected) {
                throw ValidationException::withMessages(['status' => 'Rejected evidence cannot be approved.']);
            }
            if ($locked->amount_minor === null || $locked->amount_minor < 1 || $locked->currency === null) {
                throw ValidationException::withMessages(['amount_minor' => 'A declared amount and currency are required before approval.']);
            }

            $reservation = Reservation::query()->lockForUpdate()->findOrFail($locked->reservation_id);
            if (strtoupper($locked->currency) !== $reservation->currency) {
                throw ValidationException::withMessages(['currency' => 'Evidence currency must match the reservation currency.']);
            }
            $deposit = $depositId === null
                ? Deposit::query()->where('reservation_id', $reservation->id)
                    ->where('status', DepositStatus::Due)->where('amount_minor', '<=', $locked->amount_minor)
                    ->orderBy('due_at')->lockForUpdate()->first()
                : Deposit::query()->where('reservation_id', $reservation->id)->whereKey($depositId)->lockForUpdate()->firstOrFail();

            $actor = User::query()->findOrFail($actorId);
            $detail = $this->payments->handle($actor, new FrontDeskPaymentInput(
                reservationId: $reservation->id,
                channel: PaymentChannel::BankTransfer,
                amountMinor: $locked->amount_minor,
                idempotencyKey: 'evidence-approval:'.$locked->id,
                depositId: $deposit?->id,
                transactionReference: $locked->transfer_reference ?: 'evidence-'.substr(hash('sha256', $locked->id), 0, 24),
                note: trim((string) $note) ?: null,
            ));
            $payment = $detail->payment ?? throw ValidationException::withMessages(['payment' => 'Approved evidence did not produce a posted payment.']);

            $locked->update([
                'status' => PaymentEvidenceStatus::Approved,
                'payment_id' => $payment->id,
                'tender_detail_id' => $detail->id,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'decided_at' => now(),
                'reviewer_note' => trim((string) $note) ?: null,
                'requested_information_note' => null,
            ]);
            $this->queueGuestNotification($locked, PaymentEvidenceStatus::Approved, $locked->reviewer_note);
            $this->outbox->record('guest_payment_evidence', $locked->id, 'payment_evidence.approved', [
                'evidence_id' => $locked->id,
                'reservation_id' => $reservation->id,
                'payment_id' => $payment->id,
                'guest_id' => $locked->guest_id,
            ]);

            return $payment;
        }, 3);
    }

    public function reject(GuestPaymentEvidence $evidence, string $note, ?int $actorId): GuestPaymentEvidence
    {
        return $this->decideWithoutPayment($evidence, PaymentEvidenceStatus::Rejected, $note, $actorId);
    }

    public function requestMoreInformation(GuestPaymentEvidence $evidence, string $note, ?int $actorId): GuestPaymentEvidence
    {
        return $this->decideWithoutPayment($evidence, PaymentEvidenceStatus::MoreInformationRequired, $note, $actorId);
    }

    private function decideWithoutPayment(GuestPaymentEvidence $evidence, PaymentEvidenceStatus $status, string $note, ?int $actorId): GuestPaymentEvidence
    {
        return DB::transaction(function () use ($evidence, $status, $note, $actorId): GuestPaymentEvidence {
            $locked = GuestPaymentEvidence::query()->lockForUpdate()->findOrFail($evidence->id);
            if ($locked->status === PaymentEvidenceStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Approved evidence cannot be changed. Reverse the payment instead.']);
            }
            $cleanNote = trim($note);
            if ($cleanNote === '') {
                throw ValidationException::withMessages(['reviewer_note' => 'A decision note is required.']);
            }
            $locked->update([
                'status' => $status,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'decided_at' => $status->isFinal() ? now() : null,
                'reviewer_note' => $status === PaymentEvidenceStatus::Rejected ? $cleanNote : null,
                'requested_information_note' => $status === PaymentEvidenceStatus::MoreInformationRequired ? $cleanNote : null,
            ]);
            $this->queueGuestNotification($locked, $status, $cleanNote);
            $this->outbox->record('guest_payment_evidence', $locked->id, 'payment_evidence.'.$status->value, [
                'evidence_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'guest_id' => $locked->guest_id,
                'note' => $cleanNote,
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function queueGuestNotification(GuestPaymentEvidence $evidence, PaymentEvidenceStatus $status, ?string $note): void
    {
        $guest = $evidence->guest;
        if ($guest === null || blank($guest->email)) {
            return;
        }

        $label = match ($status) {
            PaymentEvidenceStatus::Approved => 'approved',
            PaymentEvidenceStatus::Rejected => 'rejected',
            PaymentEvidenceStatus::MoreInformationRequired => 'needs more information',
            PaymentEvidenceStatus::Pending => 'is pending review',
        };
        $amount = $evidence->amount_minor === null
            ? 'the submitted transfer'
            : strtoupper((string) $evidence->currency).' '.number_format($evidence->amount_minor / 100, 2);
        $communication = Communication::query()->firstOrCreate(
            ['automation_key' => "payment-evidence:{$evidence->id}:{$status->value}"],
            [
                'guest_id' => $guest->id,
                'reservation_id' => $evidence->reservation_id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'queued',
                'subject' => 'Your bank-transfer evidence '.$label,
                'body' => "Your evidence for {$amount} {$label}.".(filled($note) ? "\n\nStaff note: ".trim((string) $note) : ''),
                'metadata' => [
                    'guest_payment_evidence_id' => $evidence->id,
                    'evidence_status' => $status->value,
                ],
            ],
        );

        if ($communication->wasRecentlyCreated) {
            $this->outbox->record('communication', $communication->id, 'communication.queued', [
                'communication_id' => $communication->id,
                'channel' => $communication->channel,
            ]);
        }
    }
}
