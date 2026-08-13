<?php

namespace App\Services;

use App\Enums\AllocationStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Tenant;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    public function __construct(
        private AvailabilityService $availability,
        private OutboxRecorder $outbox,
        private ProgramRequirementService $programRequirements,
        private ReservationConfirmationProvisioner $provisioner,
    ) {}

    public function confirm(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === ReservationStatus::Confirmed) {
                $this->provisioner->provision($locked);

                return $locked->load(['allocations.resource', 'primaryGuest', 'program']);
            }

            if (! $locked->status->canTransitionTo(ReservationStatus::Confirmed)) {
                throw new InvalidStatusTransitionException($locked->status, ReservationStatus::Confirmed);
            }
            if ($locked->status === ReservationStatus::Hold && ($locked->hold_expires_at === null || ! $locked->hold_expires_at->isFuture())) {
                throw new InvalidStatusTransitionException($locked->status, ReservationStatus::Confirmed);
            }

            $allocations = $locked->allocations()->lockForUpdate()->get();

            $this->programRequirements->assertSatisfied($locked);

            foreach ($allocations as $allocation) {
                $this->availability->assertAvailable($allocation);
            }

            foreach ($allocations as $allocation) {
                $allocation->update(['status' => AllocationStatus::Confirmed]);
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => ReservationStatus::Confirmed,
                'confirmed_at' => now(),
                'hold_expires_at' => null,
                'revision' => $locked->revision + 1,
            ]);

            $this->recordStatus($locked, $previousStatus, ReservationStatus::Confirmed);
            $this->provisioner->provision($locked);

            $this->outbox->record(
                'reservation',
                $locked->id,
                'reservation.confirmed',
                ['reservation_id' => $locked->id, 'confirmation_number' => $locked->confirmation_number],
            );

            return $locked->fresh(['allocations.resource', 'primaryGuest', 'program']);
        }, 3);
    }

    /** @param array<string, mixed> $metadata */
    public function transition(Reservation $reservation, ReservationStatus $next, ?int $holdMinutes = null, array $metadata = []): Reservation
    {
        if ($next === ReservationStatus::Confirmed) {
            return $this->confirm($reservation);
        }

        $metadata = array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return DB::transaction(function () use ($reservation, $next, $holdMinutes, $metadata): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($next)) {
                throw new InvalidStatusTransitionException($locked->status, $next);
            }

            $holdAllocations = collect();
            if ($next === ReservationStatus::Hold) {
                $holdAllocations = $locked->allocations()->lockForUpdate()->get();
                $this->programRequirements->assertSatisfied($locked);
                foreach ($holdAllocations as $allocation) {
                    $allocation->status = AllocationStatus::Tentative;
                    $this->availability->assertAvailable($allocation);
                }
            }

            $changes = [
                'status' => $next,
                'revision' => $locked->revision + 1,
            ];
            if ($next === ReservationStatus::Hold) {
                $ttl = $holdMinutes ?? (int) config('reservations.hold_ttl_minutes', 30);
                $changes['hold_expires_at'] = now()->addMinutes(max(1, $ttl));
            } elseif ($locked->status === ReservationStatus::Hold) {
                $changes['hold_expires_at'] = null;
            }
            if ($next === ReservationStatus::CheckedIn) {
                $changes['actual_start_at'] = now();
            }
            if ($next === ReservationStatus::CheckedOut) {
                $changes['actual_end_at'] = now();
            }
            if (in_array($next, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                $reason = trim((string) ($metadata['reason'] ?? ''));
                if ($reason === '') {
                    throw new \DomainException('A reason is required when cancelling a reservation or recording a no-show.');
                }
                $changes['cancelled_at'] = now();
                $changes['closure_reason'] = $reason;
                $metadata['reason'] = $reason;
            }
            $previousStatus = $locked->status;
            $locked->update($changes);
            $this->recordStatus($locked, $previousStatus, $next, $metadata);
            foreach ($holdAllocations as $allocation) {
                $allocation->save();
            }

            if (in_array($next, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true)) {
                $locked->allocations()->update(['status' => AllocationStatus::Released]);
            }

            $this->outbox->record(
                'reservation',
                $locked->id,
                'reservation.status_changed',
                [
                    'reservation_id' => $locked->id,
                    'status' => $next->value,
                    'reason' => $metadata['reason'] ?? null,
                ],
            );

            return $locked->fresh(['allocations.resource', 'primaryGuest', 'program']);
        }, 3);
    }

    public function expireDueHolds(int $batch = 100): int
    {
        $candidates = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Hold)
            ->where('hold_expires_at', '<=', now())
            ->orderBy('hold_expires_at')
            ->limit(max(1, $batch))
            ->get(['id', 'tenant_id']);
        $expired = 0;
        $context = app(TenantContext::class);
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();

        try {
            foreach ($candidates as $candidate) {
                $tenant = Tenant::query()->find($candidate->tenant_id);
                if ($tenant === null) {
                    continue;
                }
                $context->set($tenant);
                $expired += DB::transaction(function () use ($candidate): int {
                    $held = Reservation::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if ($held === null || $held->status !== ReservationStatus::Hold || $held->hold_expires_at?->isFuture()) {
                        return 0;
                    }

                    $held->allocations()->where('status', AllocationStatus::Tentative)->update(['status' => AllocationStatus::Released]);
                    $held->update([
                        'status' => ReservationStatus::Draft,
                        'hold_expires_at' => null,
                        'revision' => $held->revision + 1,
                    ]);
                    $this->recordStatus($held, ReservationStatus::Hold, ReservationStatus::Draft, ['reason' => 'hold_expired']);
                    $this->outbox->record(
                        'reservation',
                        $held->id,
                        'reservation.hold_expired',
                        ['reservation_id' => $held->id],
                    );

                    return 1;
                }, 3);
            }
        } finally {
            $previousTenant === null
                ? $context->clear()
                : $context->set($previousTenant, $previousMembership);
        }

        return $expired;
    }

    private function recordStatus(
        Reservation $reservation,
        ReservationStatus|string|null $from,
        ReservationStatus $to,
        array $metadata = [],
    ): void {
        ReservationStatusHistory::query()->create([
            'reservation_id' => $reservation->id,
            'actor_id' => auth()->id(),
            'from_status' => $from instanceof ReservationStatus ? $from : ($from ? ReservationStatus::from($from) : null),
            'to_status' => $to,
            'changed_at' => now(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
