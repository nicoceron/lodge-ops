<?php

namespace App\Services\Documents;

use App\Enums\DocumentKind;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\DocumentTemplate;
use App\Models\GuestPortalAcknowledgement;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationChange;
use Carbon\CarbonInterface;
use DomainException;

final class DocumentSnapshotFactory
{
    /** @return array<string, mixed> */
    public function build(
        DocumentKind $kind,
        Reservation $reservation,
        DocumentTemplate $template,
        string $locale,
        ?Payment $payment = null,
        ?ReservationChange $change = null,
        ?GuestPortalAcknowledgement $acknowledgement = null,
    ): array {
        $this->assertSubject($kind, $reservation, $payment, $change, $acknowledgement);
        $reservation->loadMissing([
            'property', 'primaryGuest', 'program', 'allocations.resource.category',
            'allocations.requestedCategory', 'allocations.serviceOccurrence.program', 'folioLines',
            'payments', 'deposits', 'changes',
        ]);
        $timezone = $reservation->property->timezone ?: 'UTC';

        $payload = match ($kind) {
            DocumentKind::ReservationConfirmation => $this->confirmation($reservation, $timezone),
            DocumentKind::Itinerary => $this->itinerary($reservation, $timezone),
            DocumentKind::FolioStatement => $this->folio($reservation, $timezone),
            DocumentKind::PaymentReceipt => $this->payment($reservation, $payment, $timezone),
            DocumentKind::RefundReceipt => $this->refund($reservation, $change, $timezone),
            DocumentKind::WaiverCopy => $this->waiver($reservation, $acknowledgement, $timezone),
        };

        return [
            'schema_version' => 1,
            'application_version' => (string) config('app.version', 'dev'),
            'kind' => $kind->value,
            'locale' => $locale,
            'template' => [
                'id' => $template->id,
                'version' => $template->version,
                'options' => $this->approvedOptions($template->definition ?? []),
            ],
            'property_timezone' => $timezone,
            'payload' => $payload,
        ];
    }

    private function assertSubject(DocumentKind $kind, Reservation $reservation, ?Payment $payment, ?ReservationChange $change, ?GuestPortalAcknowledgement $ack): void
    {
        if (! in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut], true)) {
            throw new DomainException('Documents require a confirmed or completed reservation.');
        }
        if ($kind === DocumentKind::PaymentReceipt && ($payment === null || $payment->reservation_id !== $reservation->id || ! in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true))) {
            throw new DomainException('Payment receipts require a succeeded or refunded payment for this reservation.');
        }
        if ($kind === DocumentKind::RefundReceipt && ($change === null || $change->reservation_id !== $reservation->id || $change->type !== 'refund_completed' || $change->status !== 'completed')) {
            throw new DomainException('Refund receipts require a completed refund for this reservation.');
        }
        if ($kind === DocumentKind::WaiverCopy && ($ack === null || $ack->reservation_id !== $reservation->id)) {
            throw new DomainException('Waiver copies require an acknowledgement for this reservation.');
        }
    }

    /** @return array<string, mixed> */
    private function base(Reservation $reservation, string $timezone): array
    {
        $guest = $reservation->primaryGuest;
        $paid = $reservation->payments->filter(fn (Payment $payment) => in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true))->sum('amount_minor');

        return [
            'reservation' => [
                'id' => $reservation->id,
                'confirmation' => $reservation->confirmation_number,
                'status' => $reservation->status->value,
                'arrival' => $this->instant($reservation->starts_at, $timezone),
                'departure' => $this->instant($reservation->ends_at, $timezone),
                'adults' => $reservation->adults,
                'children' => $reservation->children,
                'currency' => strtoupper($reservation->currency),
                'total_minor' => $reservation->total_minor,
                'paid_minor' => $paid,
                'balance_minor' => $reservation->total_minor - $paid,
            ],
            'property' => [
                'id' => $reservation->property->id,
                'name' => $reservation->property->name,
                'code' => $reservation->property->code,
                'address' => $reservation->property->address,
            ],
            'guest' => [
                'id' => $guest?->id,
                'name' => $guest === null ? null : trim($guest->first_name.' '.$guest->last_name),
                'email' => $guest?->email,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function confirmation(Reservation $reservation, string $timezone): array
    {
        return $this->base($reservation, $timezone) + [
            'allocations' => $this->allocations($reservation, $timezone),
            'price' => $reservation->price_snapshot,
            'deposit_terms' => $reservation->deposit_policy_snapshot,
            'cancellation_terms' => $reservation->cancellation_policy_snapshot,
        ];
    }

    /** @return array<string, mixed> */
    private function itinerary(Reservation $reservation, string $timezone): array
    {
        $notes = $reservation->noteTimeline()->where('kind', 'guest_request')->orderBy('occurred_at')->get(['body'])->pluck('body')->all();

        return $this->base($reservation, $timezone) + ['allocations' => $this->allocations($reservation, $timezone), 'guest_notes' => $notes];
    }

    /** @return array<string, mixed> */
    private function folio(Reservation $reservation, string $timezone): array
    {
        $base = $this->base($reservation, $timezone);
        $base['folio'] = [
            'label' => $reservation->folio_status->value === 'closed' ? 'Final' : 'Interim',
            'status' => $reservation->folio_status->value,
            'closed_at' => $reservation->folio_closed_at ? $this->instant($reservation->folio_closed_at, $timezone) : null,
            'lines' => $reservation->folioLines->sortBy([['posted_at', 'asc'], ['id', 'asc']])->values()->map(fn ($line) => [
                'id' => $line->id,
                'type' => $line->type->value,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'net_minor' => $line->net_amount_minor,
                'tax_minor' => $line->tax_amount_minor,
                'gross_minor' => $line->gross_amount_minor,
                'currency' => strtoupper($reservation->currency),
                'posted_at' => $this->instant($line->posted_at, $timezone),
                'reverses_line_id' => $line->reverses_folio_line_id,
            ])->all(),
        ];

        return $base;
    }

    /** @return array<string, mixed> */
    private function payment(Reservation $reservation, ?Payment $payment, string $timezone): array
    {
        if ($payment === null) {
            throw new \LogicException('A payment receipt requires a payment subject.');
        }
        $payment->loadMissing('tenderDetail');
        $tender = $payment->tenderDetail;
        $wording = match ($payment->channel->value) {
            'external_terminal' => 'Recorded external terminal payment; Inn did not charge the card',
            'cash' => 'Payment recorded by staff — cash',
            'bank_transfer' => 'Payment recorded by staff — bank transfer',
            'manual_other' => 'Payment recorded by staff — manual other',
            default => 'Payment reported by provider',
        };
        $base = $this->base($reservation, $timezone);
        $base['payment'] = [
            'id' => $payment->id,
            'status' => $payment->status->value,
            'origin' => $payment->origin->value,
            'method' => $payment->method,
            'channel' => $payment->channel->value,
            'entry_mode' => $payment->entry_mode->value,
            'wording' => $wording,
            'amount_minor' => $payment->amount_minor,
            'currency' => strtoupper($payment->currency),
            'reference' => $tender === null ? $payment->provider_reference : $tender->transaction_reference,
            'card_brand' => $tender?->card_brand,
            'card_last_four' => $tender?->card_last_four,
            'processed_at' => $payment->processed_at ? $this->instant($payment->processed_at, $timezone) : null,
        ];

        return $base;
    }

    /** @return array<string, mixed> */
    private function refund(Reservation $reservation, ?ReservationChange $change, string $timezone): array
    {
        $base = $this->base($reservation, $timezone);
        $paidMinor = $reservation->payments->filter(fn (Payment $payment) => in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Refunded], true))->sum('amount_minor');
        $refundedMinor = $reservation->changes->where('type', 'refund_completed')->where('status', 'completed')->sum('amount_minor');
        $sourcePayment = Payment::query()->whereKey(data_get($change->metadata, 'payment_id'))->where('reservation_id', $reservation->id)->first();
        $base['refund'] = [
            'id' => $change->id,
            'amount_minor' => $change->amount_minor,
            'currency' => strtoupper($change->currency ?? $reservation->currency),
            'reference' => $change->reference,
            'reason' => data_get($change->metadata, 'reason'),
            'source_payment_id' => data_get($change->metadata, 'payment_id'),
            'source_payment_reference' => $sourcePayment?->provider_reference,
            'paid_minor' => $paidMinor,
            'refunded_minor' => $refundedMinor,
            'remaining_paid_minor' => max(0, $paidMinor - $refundedMinor),
            'completed_at' => $this->instant($change->occurred_at, $timezone),
        ];

        return $base;
    }

    /** @return array<string, mixed> */
    private function waiver(Reservation $reservation, ?GuestPortalAcknowledgement $ack, string $timezone): array
    {
        if ($ack === null) {
            throw new DomainException('Waiver copies require an acknowledgement.');
        }
        $ack->loadMissing(['document', 'guest']);
        if (! hash_equals($ack->document_hash, $ack->document->body_hash)) {
            throw new DomainException('The acknowledged waiver content no longer matches its recorded hash.');
        }
        $base = $this->base($reservation, $timezone);
        $base['waiver'] = [
            'document_id' => $ack->document->id,
            'title' => $ack->document->title,
            'body' => $ack->document->body,
            'body_hash' => $ack->document_hash,
            'version' => $ack->document->version,
            'acknowledgement_id' => $ack->id,
            'acknowledged_at' => $this->instant($ack->acknowledged_at, $timezone),
            'acknowledged_by' => trim($ack->guest->first_name.' '.$ack->guest->last_name),
        ];

        return $base;
    }

    /** @return list<array<string, mixed>> */
    private function allocations(Reservation $reservation, string $timezone): array
    {
        return $reservation->allocations->sortBy([['starts_at', 'asc'], ['id', 'asc']])->values()->map(fn ($allocation) => [
            'id' => $allocation->id,
            'status' => $allocation->status->value,
            'assignment' => $allocation->assignmentLabel(),
            'category' => $allocation->requestedCategoryName(),
            'service' => $allocation->serviceOccurrence?->program?->name,
            'meeting_point' => $allocation->serviceOccurrence?->meeting_point,
            'starts_at' => $this->instant($allocation->starts_at, $timezone),
            'ends_at' => $this->instant($allocation->ends_at, $timezone),
        ])->all();
    }

    /** @return array{utc:string,local:string} */
    private function instant(CarbonInterface $value, string $timezone): array
    {
        return ['utc' => $value->clone()->utc()->toIso8601String(), 'local' => $value->clone()->setTimezone($timezone)->format('Y-m-d H:i T')];
    }

    /** @param array<string, mixed> $definition @return array<string, bool|string|int|float|null> */
    private function approvedOptions(array $definition): array
    {
        $options = [];
        $allowed = ['locale', 'show_balance', 'show_terms', 'show_allocations', 'show_reference'];
        foreach ($definition as $key => $value) {
            if (in_array($key, $allowed, true) && (is_scalar($value) || $value === null)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
