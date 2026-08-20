<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Models\ReservationMilestoneOccurrence;
use App\Models\Tenant;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchReservationMilestoneOccurrence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $occurrenceId,
        public readonly string $claimToken,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(TenantContext $context, OutboxRecorder $outbox): void
    {
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();
        $context->clear();

        try {
            $context->set(Tenant::query()->findOrFail($this->tenantId));
            DB::transaction(function () use ($outbox): void {
                $occurrence = ReservationMilestoneOccurrence::query()
                    ->whereKey($this->occurrenceId)->where('state', 'claimed')
                    ->where('claim_token', $this->claimToken)->lockForUpdate()->first();
                if ($occurrence === null) {
                    return;
                }
                $reservation = Reservation::query()->whereKey($occurrence->reservation_id)->lockForUpdate()->firstOrFail();
                if (in_array($reservation->status->value, ['cancelled', 'no_show'], true)) {
                    $occurrence->forceFill([
                        'state' => 'suppressed', 'claim_token' => null, 'claimed_at' => null,
                        'superseded_at' => now(), 'supersession_reason' => 'reservation_'.$reservation->status->value,
                    ])->save();

                    return;
                }
                if ((int) $reservation->revision !== $occurrence->reservation_revision) {
                    $occurrence->forceFill([
                        'state' => 'superseded', 'claim_token' => null, 'claimed_at' => null,
                        'superseded_at' => now(), 'supersession_reason' => 'reservation_amended_after_claim',
                    ])->save();

                    return;
                }

                [$event, $payload] = $this->event($occurrence->key, $reservation->id);
                $message = $outbox->record('reservation', $reservation->id, $event, [
                    ...$payload,
                    'milestone_occurrence_id' => $occurrence->id,
                    'rule_version' => $occurrence->rule_version,
                ], $occurrence->target_at);
                $occurrence->forceFill([
                    'state' => 'dispatched',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'attempts' => $occurrence->attempts + 1,
                    'outbox_id' => $message->id,
                    'dispatched_at' => now(),
                    'last_error' => null,
                ])->save();
            }, 3);
        } finally {
            $context->clear();
            if ($previousTenant !== null) {
                $context->set($previousTenant, $previousMembership);
            }
        }
    }

    /** @return array{string,array<string,mixed>} */
    private function event(string $key, string $reservationId): array
    {
        if ($key === 'arrival_7_day') {
            return ['reservation.arrival_approaching', ['reservation_id' => $reservationId, 'days_before' => 7]];
        }
        if ($key === 'arrival_1_day') {
            return ['reservation.arrival_approaching', ['reservation_id' => $reservationId, 'days_before' => 1]];
        }
        if ($key === 'post_checkout') {
            return ['reservation.checkout_completed', ['reservation_id' => $reservationId]];
        }
        if (str_starts_with($key, 'deposit_overdue_')) {
            return ['deposit.overdue', ['reservation_id' => $reservationId, 'deposit_id' => substr($key, 16)]];
        }

        throw new \DomainException('Unknown reservation milestone occurrence.');
    }

    public function failed(?Throwable $exception): void
    {
        ReservationMilestoneOccurrence::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->whereKey($this->occurrenceId)
            ->where('claim_token', $this->claimToken)->update([
                'state' => 'pending', 'claim_token' => null, 'claimed_at' => null,
                'attempts' => DB::raw('attempts + 1'), 'last_error' => 'Occurrence dispatch failed.',
            ]);
    }
}
