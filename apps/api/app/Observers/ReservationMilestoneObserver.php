<?php

namespace App\Observers;

use App\Models\Reservation;
use App\Services\Communications\ReservationMilestoneScheduler;
use Illuminate\Support\Facades\DB;

class ReservationMilestoneObserver
{
    public function saved(Reservation $reservation): void
    {
        if (! $reservation->wasRecentlyCreated && ! $reservation->wasChanged(['starts_at', 'ends_at', 'actual_end_at', 'status', 'revision', 'property_id'])) {
            return;
        }

        $id = $reservation->id;
        DB::afterCommit(function () use ($id): void {
            $fresh = Reservation::query()->find($id);
            if ($fresh !== null) {
                app(ReservationMilestoneScheduler::class)->synchronize($fresh);
            }
        });
    }
}
