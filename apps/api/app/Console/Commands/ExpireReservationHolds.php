<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

class ExpireReservationHolds extends Command
{
    protected $signature = 'reservation-holds:expire {--batch=100 : Maximum holds to expire}';

    protected $description = 'Release capacity held by expired reservation holds';

    public function handle(ReservationService $service): int
    {
        $expired = $service->expireDueHolds((int) $this->option('batch'));
        $this->info("Expired {$expired} reservation hold(s).");

        return self::SUCCESS;
    }
}
