<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingQuoteStatus;
use App\Enums\FolioLineType;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\BookingQuote;
use App\Models\Reservation;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AmendReservation
{
    public function __construct(
        private readonly BookingQuoteService $quotes,
        private readonly AvailabilityService $availability,
        private readonly FolioService $folio,
        private readonly ReservationPaymentScheduleService $paymentSchedule,
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(Reservation $reservation, array $input, ?int $actorId): Reservation
    {
        $quote = $this->quotes->createAmendment($reservation, $input);

        return DB::transaction(function () use ($reservation, $quote, $actorId): Reservation {
            $locked = Reservation::query()->with('allocations.requestedCategory')->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, [ReservationStatus::Hold, ReservationStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['status' => 'Only held or confirmed reservations may be amended.']);
            }
            $lockedQuote = BookingQuote::query()->with('lines')->lockForUpdate()->findOrFail($quote->id);
            if ($lockedQuote->status !== BookingQuoteStatus::Pending || ! $lockedQuote->expires_at->isFuture()) {
                throw ValidationException::withMessages(['rate_plan_id' => 'The amendment quote expired. Refresh it and try again.']);
            }
            if ($lockedQuote->property_id !== $locked->property_id || $lockedQuote->currency !== $locked->currency) {
                throw ValidationException::withMessages(['rate_plan_id' => 'An amendment cannot change property or currency.']);
            }
            if (! hash_equals($lockedQuote->checksum, $this->quotes->checksumFor($lockedQuote))) {
                throw ValidationException::withMessages(['rate_plan_id' => 'The amendment quote failed its integrity check.']);
            }

            $before = $this->changes->snapshot($locked);
            $active = $locked->allocations->where('status', '!=', AllocationStatus::Released);
            $stayAllocations = $active->filter(fn (Allocation $allocation): bool => $allocation->requestedCategory?->counts_as_stay === true);
            if ($stayAllocations->count() !== 1) {
                throw ValidationException::withMessages(['reservation' => 'A guarded amendment requires exactly one active stay allocation.']);
            }
            $otherAllocations = $active->reject(fn (Allocation $allocation): bool => $stayAllocations->contains('id', $allocation->id));
            if ($otherAllocations->contains(fn (Allocation $allocation): bool => $allocation->starts_at->lessThan($lockedQuote->starts_at)
                || $allocation->ends_at->greaterThan($lockedQuote->ends_at))) {
                throw ValidationException::withMessages(['starts_at' => 'The amended stay must contain every retained activity allocation.']);
            }

            $stayAllocations->each(fn (Allocation $allocation) => $allocation->update(['status' => AllocationStatus::Released]));
            $replacement = Allocation::query()->create([
                'reservation_id' => $locked->id,
                'requested_category_id' => $lockedQuote->resource_category_id,
                'resource_id' => $lockedQuote->resource_id,
                'status' => $locked->status === ReservationStatus::Confirmed ? AllocationStatus::Confirmed : AllocationStatus::Tentative,
                'starts_at' => $lockedQuote->starts_at,
                'ends_at' => $lockedQuote->ends_at,
                'quantity' => 1,
            ]);
            $this->availability->assertAvailable($replacement);

            $subtotalDelta = $lockedQuote->subtotal_minor - $locked->subtotal_minor;
            $taxDelta = $lockedQuote->tax_minor - $locked->tax_minor;
            $totalDelta = $lockedQuote->total_minor - $locked->total_minor;
            if ($totalDelta !== 0) {
                $this->folio->append(
                    $locked,
                    FolioLineType::Adjustment,
                    'Reservation amendment · re-priced stay',
                    1000,
                    $subtotalDelta,
                    $actorId,
                    ['amendment_quote_id' => $lockedQuote->id],
                    $taxDelta,
                    true,
                );
            }

            $locked->update([
                'program_id' => $lockedQuote->program_id,
                'booking_quote_id' => $lockedQuote->id,
                'starts_at' => $lockedQuote->starts_at,
                'ends_at' => $lockedQuote->ends_at,
                'adults' => $lockedQuote->adults,
                'children' => $lockedQuote->children,
                'subtotal_minor' => $lockedQuote->subtotal_minor,
                'tax_minor' => $lockedQuote->tax_minor,
                'total_minor' => $lockedQuote->total_minor,
                'price_snapshot' => [
                    'quote_id' => $lockedQuote->id,
                    'checksum' => $lockedQuote->checksum,
                    'lines' => $lockedQuote->lines->map->only([
                        'type', 'description', 'service_on', 'quantity_thousandths', 'unit_amount_minor',
                        'net_amount_minor', 'tax_amount_minor', 'gross_amount_minor', 'metadata',
                    ])->all(),
                ],
                'deposit_policy_snapshot' => $lockedQuote->deposit_policy_snapshot,
                'cancellation_policy_snapshot' => $lockedQuote->cancellation_policy_snapshot,
                'revision' => $locked->revision + 1,
            ]);
            $lockedQuote->update([
                'status' => BookingQuoteStatus::Committed,
                'reservation_id' => $locked->id,
                'committed_at' => now(),
            ]);
            $paymentEffects = $this->paymentSchedule->rebuild($locked, 'Superseded by reservation amendment', $actorId);
            $locked->unsetRelation('allocations');
            $change = $this->changes->record($locked, 'amendment', [
                'actor_id' => $actorId,
                'amount_minor' => $totalDelta,
                'before_snapshot' => $before,
                'after_snapshot' => $this->changes->snapshot($locked->fresh('allocations')),
                'metadata' => [
                    'quote_id' => $lockedQuote->id,
                    'replacement_allocation_id' => $replacement->id,
                    'price_delta_minor' => $totalDelta,
                    'deposit_payment_effects' => $paymentEffects,
                ],
            ]);
            $this->outbox->record('reservation', $locked->id, 'reservation.amended', [
                'reservation_id' => $locked->id,
                'change_id' => $change->id,
                'price_delta_minor' => $totalDelta,
            ]);

            return $locked->fresh(['allocations.requestedCategory', 'allocations.resource', 'changes.actor']);
        }, 3);
    }
}
