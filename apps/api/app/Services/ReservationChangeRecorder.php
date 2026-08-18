<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\ReservationChange;

final class ReservationChangeRecorder
{
    /** @param array<string, mixed> $attributes */
    public function record(Reservation $reservation, string $type, array $attributes = []): ReservationChange
    {
        return ReservationChange::query()->create([
            'reservation_id' => $reservation->id,
            'actor_id' => $attributes['actor_id'] ?? auth()->id(),
            'parent_change_id' => $attributes['parent_change_id'] ?? null,
            'type' => $type,
            'status' => $attributes['status'] ?? 'completed',
            'currency' => $attributes['currency'] ?? $reservation->currency,
            'amount_minor' => $attributes['amount_minor'] ?? null,
            'reference' => $attributes['reference'] ?? null,
            'deduplication_key' => $attributes['deduplication_key'] ?? null,
            'before_snapshot' => $attributes['before_snapshot'] ?? null,
            'after_snapshot' => $attributes['after_snapshot'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function snapshot(Reservation $reservation): array
    {
        $reservation->loadMissing('allocations');

        return [
            'status' => $reservation->status->value,
            'revision' => $reservation->revision,
            'starts_at' => $reservation->starts_at->toIso8601String(),
            'ends_at' => $reservation->ends_at->toIso8601String(),
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'program_id' => $reservation->program_id,
            'booking_quote_id' => $reservation->booking_quote_id,
            'currency' => $reservation->currency,
            'subtotal_minor' => $reservation->subtotal_minor,
            'tax_minor' => $reservation->tax_minor,
            'total_minor' => $reservation->total_minor,
            'deposit_policy_snapshot' => $reservation->deposit_policy_snapshot,
            'cancellation_policy_snapshot' => $reservation->cancellation_policy_snapshot,
            'allocations' => $reservation->allocations->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'requested_category_id' => $allocation->requested_category_id,
                'resource_id' => $allocation->resource_id,
                'service_occurrence_id' => $allocation->service_occurrence_id,
                'status' => $allocation->status->value,
                'starts_at' => $allocation->starts_at->toIso8601String(),
                'ends_at' => $allocation->ends_at->toIso8601String(),
                'quantity' => $allocation->quantity,
            ])->values()->all(),
        ];
    }
}
