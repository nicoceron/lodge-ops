<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Services\Automation\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReservationCompanionService
{
    public function __construct(
        private readonly ReservationChangeRecorder $changes,
        private readonly OutboxRecorder $outbox,
    ) {}

    /** @param list<array<string, mixed>> $companions */
    public function replace(Reservation $reservation, array $companions, int $expectedRevision, ?int $actorId): Reservation
    {
        return DB::transaction(function () use ($reservation, $companions, $expectedRevision, $actorId): Reservation {
            $locked = Reservation::query()->with(['guests', 'allocations'])->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['expected_revision' => 'This reservation changed. Refresh before editing companions.']);
            }
            if (! in_array($locked->status, [ReservationStatus::Draft, ReservationStatus::Hold, ReservationStatus::Confirmed], true)) {
                throw ValidationException::withMessages(['status' => 'Companions may only be changed before check-in.']);
            }
            $normalized = collect($companions)->values()->map(function (array $item, int $index) use ($locked): array {
                $guest = Guest::query()->findOrFail((string) ($item['guest_id'] ?? ''));
                if ($guest->id === $locked->primary_guest_id) {
                    throw ValidationException::withMessages(["companions.{$index}.guest_id" => 'The lead guest cannot be added or removed as a companion.']);
                }

                return [
                    'guest_id' => $guest->id,
                    'role' => 'companion',
                    'sort_order' => $index + 1,
                    'operational_preferences' => array_filter([
                        'dietary' => $item['dietary'] ?? null,
                        'allergies' => $item['allergies'] ?? null,
                        'meal_notes' => $item['meal_notes'] ?? null,
                    ], fn ($value) => $value !== null && $value !== ''),
                ];
            });
            if ($normalized->pluck('guest_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['companions' => 'A guest may appear only once in the companion list.']);
            }
            $declaredOccupancy = $locked->adults + $locked->children + $locked->infants;
            if ($normalized->count() + 1 > $declaredOccupancy) {
                throw ValidationException::withMessages([
                    'companions' => 'Companions exceed the server-priced occupancy. Complete a guarded priced amendment first.',
                ]);
            }

            $before = $this->changes->snapshot($locked);
            $before['companions'] = $locked->guests->where('id', '!=', $locked->primary_guest_id)->pluck('id')->values()->all();
            ReservationGuest::query()->where('reservation_id', $locked->id)->where('guest_id', '!=', $locked->primary_guest_id)->delete();
            foreach ($normalized as $item) {
                ReservationGuest::query()->create(['reservation_id' => $locked->id, ...$item]);
            }
            ReservationGuest::query()->firstOrCreate(
                ['reservation_id' => $locked->id, 'guest_id' => $locked->primary_guest_id],
                ['role' => 'primary', 'sort_order' => 0],
            );
            $locked->update(['revision' => $locked->revision + 1]);
            $after = $this->changes->snapshot($locked->fresh('allocations'));
            $after['companions'] = $normalized->pluck('guest_id')->all();
            $change = $this->changes->record($locked, 'companions_changed', [
                'actor_id' => $actorId,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'metadata' => ['occupancy_revalidated' => $declaredOccupancy, 'pricing_changed' => false],
            ]);
            $this->outbox->record('reservation', $locked->id, 'reservation.companions_changed', [
                'reservation_id' => $locked->id,
                'change_id' => $change->id,
                'guest_ids' => $normalized->pluck('guest_id')->all(),
                'kitchen_projection_changed' => true,
            ]);

            return $locked->fresh(['primaryGuest', 'guests', 'changes.actor']);
        }, 3);
    }
}
