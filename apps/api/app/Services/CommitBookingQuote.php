<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\BookingQuoteStatus;
use App\Enums\FolioLineType;
use App\Enums\ReservationStatus;
use App\Models\Allocation;
use App\Models\BookingQuote;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommitBookingQuote
{
    public function __construct(
        private readonly BookingQuoteService $quotes,
        private readonly AvailabilityService $availability,
        private readonly FolioService $folio,
    ) {}

    /** @param array<string, mixed> $guestData @param list<string> $companionIds */
    public function handle(
        BookingQuote $quote,
        ?string $guestId,
        array $guestData = [],
        array $companionIds = [],
        ?string $source = null,
        ?string $notes = null,
    ): Reservation {
        return DB::transaction(function () use ($quote, $guestId, $guestData, $companionIds, $source, $notes): Reservation {
            $locked = BookingQuote::query()->with('lines')->lockForUpdate()->findOrFail($quote->id);
            if ($locked->status === BookingQuoteStatus::Committed && $locked->reservation_id !== null) {
                return Reservation::query()->findOrFail($locked->reservation_id);
            }
            if ($locked->status !== BookingQuoteStatus::Pending || ! $locked->expires_at->isFuture()) {
                throw ValidationException::withMessages(['rate_plan_id' => 'This quote expired. Refresh the price and availability.']);
            }
            if (! hash_equals($locked->checksum, $this->quotes->checksumFor($locked))) {
                throw ValidationException::withMessages(['rate_plan_id' => 'The quote snapshot failed its integrity check.']);
            }

            Property::query()->whereKey($locked->property_id)->lockForUpdate()->firstOrFail();
            $guest = $guestId === null
                ? Guest::query()->create([
                    'first_name' => trim((string) ($guestData['first_name'] ?? '')),
                    'last_name' => trim((string) ($guestData['last_name'] ?? '')) ?: null,
                    'email' => strtolower(trim((string) ($guestData['email'] ?? ''))) ?: null,
                    'phone' => trim((string) ($guestData['phone'] ?? '')) ?: null,
                    'language' => $guestData['language'] ?? null,
                    'preferences' => array_filter([
                        'dietary' => $guestData['dietary'] ?? null,
                    ]),
                ])
                : Guest::query()->findOrFail($guestId);
            if (trim($guest->first_name) === '') {
                throw ValidationException::withMessages(['guest_first_name' => 'A guest name is required.']);
            }

            $reservation = Reservation::query()->create([
                'property_id' => $locked->property_id,
                'program_id' => $locked->program_id,
                'primary_guest_id' => $guest->id,
                'booking_quote_id' => $locked->id,
                'confirmation_number' => 'RSV-'.Str::upper((string) Str::ulid()),
                'status' => ReservationStatus::Hold,
                'source' => $source,
                'starts_at' => $locked->starts_at,
                'ends_at' => $locked->ends_at,
                'adults' => $locked->adults,
                'children' => $locked->children,
                'currency' => $locked->currency,
                'subtotal_minor' => $locked->subtotal_minor,
                'tax_minor' => $locked->tax_minor,
                'total_minor' => $locked->total_minor,
                'price_snapshot' => [
                    'quote_id' => $locked->id,
                    'checksum' => $locked->checksum,
                    'lines' => $locked->lines->map->only([
                        'type', 'description', 'service_on', 'quantity_thousandths', 'unit_amount_minor',
                        'net_amount_minor', 'tax_amount_minor', 'gross_amount_minor', 'metadata',
                    ])->all(),
                ],
                'deposit_policy_snapshot' => $locked->deposit_policy_snapshot,
                'cancellation_policy_snapshot' => $locked->cancellation_policy_snapshot,
                'hold_expires_at' => $locked->expires_at,
                'notes' => $notes,
            ]);
            $allocation = Allocation::query()->create([
                'reservation_id' => $reservation->id,
                'requested_category_id' => $locked->resource_category_id,
                'resource_id' => $locked->resource_id,
                'status' => AllocationStatus::Tentative,
                'starts_at' => $locked->starts_at,
                'ends_at' => $locked->ends_at,
                'quantity' => 1,
            ]);
            $this->availability->assertAvailable($allocation);

            foreach (array_values(array_unique([$guest->id, ...$companionIds])) as $id) {
                $companion = Guest::query()->findOrFail($id);
                ReservationGuest::query()->create([
                    'reservation_id' => $reservation->id,
                    'guest_id' => $companion->id,
                    'role' => $companion->id === $guest->id ? 'primary' : 'guest',
                ]);
            }
            foreach ($locked->lines as $line) {
                $this->folio->append(
                    $reservation,
                    FolioLineType::Charge,
                    $line->description,
                    $line->quantity_thousandths,
                    $line->unit_amount_minor,
                    auth()->id(),
                    ['booking_quote_line_id' => $line->id],
                    $line->tax_amount_minor,
                    true,
                );
            }

            $locked->update([
                'status' => BookingQuoteStatus::Committed,
                'reservation_id' => $reservation->id,
                'committed_at' => now(),
            ]);

            return $reservation->fresh(['allocations.resource', 'allocations.requestedCategory', 'primaryGuest', 'bookingQuote.lines']);
        }, 3);
    }
}
