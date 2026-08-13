<?php

namespace App\Console\Commands;

use App\Enums\DepositStatus;
use App\Enums\ReservationStatus;
use App\Models\Deposit;
use App\Models\Reservation;
use App\Models\ReservationAutomationMilestone;
use App\Models\Tenant;
use App\Services\Automation\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchReservationMilestones extends Command
{
    protected $signature = 'reservation-milestones:dispatch {--at= : Evaluate milestones at an ISO-8601 instant}';

    protected $description = 'Dispatch one-time pre-arrival, overdue-deposit, and post-checkout reservation events';

    public function handle(TenantContext $context, OutboxRecorder $outbox): int
    {
        $now = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at'))->utc() : CarbonImmutable::now('UTC');
        $count = 0;
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();

        try {
            Tenant::query()->where('is_active', true)->orderBy('id')->each(function (Tenant $tenant) use ($context, $outbox, $now, &$count): void {
                $context->set($tenant);
                Reservation::query()
                    ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::CheckedIn, ReservationStatus::CheckedOut])
                    ->where('ends_at', '>=', $now->subDays(7))
                    ->orderBy('starts_at')
                    ->chunkById(100, function ($reservations) use ($outbox, $now, &$count): void {
                        foreach ($reservations as $reservation) {
                            if ($reservation->status === ReservationStatus::CheckedOut) {
                                $count += $this->record(
                                    $reservation,
                                    'post_checkout',
                                    'reservation.checkout_completed',
                                    ['reservation_id' => $reservation->id],
                                    $outbox,
                                    $now,
                                );

                                continue;
                            }

                            if ($reservation->starts_at->greaterThan($now->addDay()) && $reservation->starts_at->lessThanOrEqualTo($now->addDays(7))) {
                                $count += $this->record(
                                    $reservation,
                                    'arrival_7_day',
                                    'reservation.arrival_approaching',
                                    ['reservation_id' => $reservation->id, 'days_before' => 7],
                                    $outbox,
                                    $now,
                                );
                            }
                            if ($reservation->starts_at->greaterThan($now) && $reservation->starts_at->lessThanOrEqualTo($now->addDay())) {
                                $count += $this->record(
                                    $reservation,
                                    'arrival_1_day',
                                    'reservation.arrival_approaching',
                                    ['reservation_id' => $reservation->id, 'days_before' => 1],
                                    $outbox,
                                    $now,
                                );
                            }

                            Deposit::query()
                                ->where('reservation_id', $reservation->id)
                                ->where('status', DepositStatus::Due)
                                ->whereNotNull('due_at')
                                ->where('due_at', '<=', $now)
                                ->get()
                                ->each(function (Deposit $deposit) use ($reservation, $outbox, $now, &$count): void {
                                    $count += $this->record(
                                        $reservation,
                                        "deposit_overdue_{$deposit->id}",
                                        'deposit.overdue',
                                        ['reservation_id' => $reservation->id, 'deposit_id' => $deposit->id],
                                        $outbox,
                                        $now,
                                    );
                                });
                        }
                    });
            });
        } finally {
            $context->clear();
            if ($previousTenant !== null) {
                $context->set($previousTenant, $previousMembership);
            }
        }

        $this->info("Dispatched {$count} new reservation milestone event(s).");

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $payload */
    private function record(
        Reservation $reservation,
        string $key,
        string $event,
        array $payload,
        OutboxRecorder $outbox,
        CarbonImmutable $now,
    ): int {
        return DB::transaction(function () use ($reservation, $key, $event, $payload, $outbox, $now): int {
            $milestone = ReservationAutomationMilestone::query()->firstOrCreate(
                ['reservation_id' => $reservation->id, 'key' => $key],
                ['occurred_at' => $now],
            );
            if (! $milestone->wasRecentlyCreated) {
                return 0;
            }

            $outbox->record('reservation', $reservation->id, $event, $payload, $now);

            return 1;
        }, 3);
    }
}
