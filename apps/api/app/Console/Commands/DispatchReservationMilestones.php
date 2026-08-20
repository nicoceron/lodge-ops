<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\SchedulerHeartbeat;
use App\Models\Tenant;
use App\Services\Communications\ReservationMilestoneScheduler;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class DispatchReservationMilestones extends Command
{
    protected $signature = 'reservation-milestones:dispatch {--at= : Claim occurrences due at an ISO-8601 instant} {--batch=100}';

    protected $description = 'Materialize versioned property-local milestones and claim due occurrences exactly once';

    public function handle(TenantContext $context, ReservationMilestoneScheduler $scheduler): int
    {
        $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at'))->utc() : CarbonImmutable::now('UTC');
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();
        $materialized = 0;

        try {
            Tenant::query()->where('is_active', true)->orderBy('id')->each(function (Tenant $tenant) use ($context, $scheduler, &$materialized): void {
                $context->set($tenant);
                Reservation::query()->whereIn('status', [
                    ReservationStatus::Confirmed,
                    ReservationStatus::CheckedIn,
                    ReservationStatus::CheckedOut,
                    ReservationStatus::Cancelled,
                    ReservationStatus::NoShow,
                ])->orderBy('id')->chunkById(100, function ($reservations) use ($scheduler, &$materialized): void {
                    foreach ($reservations as $reservation) {
                        $materialized += $scheduler->synchronize($reservation);
                    }
                });
                $context->clear();
            });
        } finally {
            $context->clear();
            if ($previousTenant !== null) {
                $context->set($previousTenant, $previousMembership);
            }
        }

        $claimed = $scheduler->claimDue($at, (int) $this->option('batch'));
        SchedulerHeartbeat::query()->updateOrCreate(['name' => 'reservation-milestones'], [
            'last_seen_at' => now(),
            'node' => gethostname() ?: 'unknown',
            'metadata' => ['materialized' => $materialized, 'claimed' => count($claimed)],
        ]);
        $this->info("Materialized {$materialized}; claimed ".count($claimed).' due occurrence(s).');

        return self::SUCCESS;
    }
}
