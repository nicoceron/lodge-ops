<?php

namespace App\Services\Communications;

use App\Enums\DepositStatus;
use App\Jobs\DispatchReservationMilestoneOccurrence;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Models\ReservationMilestoneOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ReservationMilestoneScheduler
{
    public function synchronize(Reservation $reservation): int
    {
        $reservation->loadMissing('property');
        $revision = max(1, (int) $reservation->revision);
        $timezone = $reservation->property->timezone;

        if (in_array($reservation->status->value, ['cancelled', 'no_show'], true)) {
            return ReservationMilestoneOccurrence::query()
                ->where('reservation_id', $reservation->id)->whereIn('state', ['pending', 'claimed'])
                ->update([
                    'state' => 'suppressed',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'superseded_at' => now(),
                    'supersession_reason' => 'reservation_'.$reservation->status->value,
                ]);
        }

        $definitions = [];
        if (in_array($reservation->status->value, ['confirmed', 'checked_in'], true)) {
            $definitions = [
                'arrival_7_day' => $reservation->starts_at->setTimezone($timezone)->subDays(7),
                'arrival_1_day' => $reservation->starts_at->setTimezone($timezone)->subDay(),
            ];
        }
        if (in_array($reservation->status->value, ['checked_out'], true)) {
            $definitions['post_checkout'] = ($reservation->actual_end_at ?? $reservation->ends_at)
                ->setTimezone($timezone);
        }
        if (! in_array($reservation->status->value, ['checked_out'], true)) {
            Deposit::query()->where('reservation_id', $reservation->id)->where('status', DepositStatus::Due)->whereNotNull('due_at')->get()
                ->each(function (Deposit $deposit) use (&$definitions, $timezone): void {
                    $definitions['deposit_overdue_'.$deposit->id] = $deposit->due_at->setTimezone($timezone);
                });
        }

        return DB::transaction(function () use ($reservation, $revision, $timezone, $definitions): int {
            ReservationMilestoneOccurrence::query()
                ->where('reservation_id', $reservation->id)
                ->where('reservation_revision', '<', $revision)
                ->whereIn('state', ['pending', 'claimed'])
                ->update([
                    'state' => 'superseded',
                    'claim_token' => null,
                    'claimed_at' => null,
                    'superseded_at' => now(),
                    'supersession_reason' => 'reservation_amended',
                ]);

            $obsolete = ReservationMilestoneOccurrence::query()
                ->where('reservation_id', $reservation->id)
                ->where('reservation_revision', $revision)
                ->whereIn('state', ['pending', 'claimed']);
            if ($definitions === []) {
                $obsolete->update([
                    'state' => 'suppressed', 'claim_token' => null, 'claimed_at' => null,
                    'superseded_at' => now(), 'supersession_reason' => 'milestone_no_longer_applicable',
                ]);
            } else {
                $obsolete->whereNotIn('key', array_keys($definitions))->update([
                    'state' => 'suppressed', 'claim_token' => null, 'claimed_at' => null,
                    'superseded_at' => now(), 'supersession_reason' => 'milestone_no_longer_applicable',
                ]);
            }

            $created = 0;
            foreach ($definitions as $key => $localTarget) {
                $occurrence = ReservationMilestoneOccurrence::query()->firstOrCreate(
                    [
                        'reservation_id' => $reservation->id,
                        'key' => $key,
                        'reservation_revision' => $revision,
                    ],
                    [
                        'property_id' => $reservation->property_id,
                        'rule_version' => config('communications.milestones.rule_version'),
                        'policy_version' => config('communications.milestones.policy_version').':dst-shift-forward-ambiguous-standard',
                        'timezone' => $timezone,
                        'target_local' => $localTarget->format('Y-m-d H:i:s'),
                        'target_at' => $localTarget->utc(),
                        'state' => 'pending',
                    ],
                );
                $created += (int) $occurrence->wasRecentlyCreated;
            }

            return $created;
        }, 3);
    }

    /** @return list<array{id:string,tenant_id:string,claim_token:string}> */
    public function claimDue(CarbonImmutable $at, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $claimed = DB::transaction(function () use ($at, $limit) {
            $token = (string) Str::uuid();
            $staleBefore = now()->subMinutes(
                (int) config('communications.milestones.claim_stale_minutes', 10),
            );
            $query = ReservationMilestoneOccurrence::withoutGlobalScopes()
                ->where('target_at', '<=', $at)
                ->where(function (Builder $query) use ($staleBefore): void {
                    $query->where('state', 'pending')
                        ->orWhere(function (Builder $claimed) use ($staleBefore): void {
                            $claimed->where('state', 'claimed')->where('claimed_at', '<=', $staleBefore);
                        });
                })
                ->orderBy('target_at')->limit($limit);
            $rows = DB::connection()->getDriverName() === 'pgsql'
                ? $query->lock('for update skip locked')->get(['id', 'tenant_id'])
                : $query->lockForUpdate()->get(['id', 'tenant_id']);
            if ($rows->isEmpty()) {
                return collect();
            }
            ReservationMilestoneOccurrence::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))
                ->update(['state' => 'claimed', 'claim_token' => $token, 'claimed_at' => now()]);

            return $rows->map(fn ($row): array => ['id' => $row->id, 'tenant_id' => $row->tenant_id, 'claim_token' => $token]);
        }, 3);

        $items = $claimed->values()->all();
        foreach ($items as $item) {
            try {
                DispatchReservationMilestoneOccurrence::dispatch($item['tenant_id'], $item['id'], $item['claim_token'])
                    ->onQueue('automations');
            } catch (Throwable $exception) {
                ReservationMilestoneOccurrence::withoutGlobalScopes()
                    ->where('tenant_id', $item['tenant_id'])->whereKey($item['id'])
                    ->where('state', 'claimed')->where('claim_token', $item['claim_token'])
                    ->update([
                        'state' => 'pending',
                        'claim_token' => null,
                        'claimed_at' => null,
                        'last_error' => 'Milestone job enqueue failed; pending durable replay.',
                    ]);

                report($exception);
            }
        }

        return $items;
    }
}
