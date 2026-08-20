<?php

namespace App\Console\Commands;

use App\Models\CommunicationDeliveryEvent;
use App\Models\DeliveryAttempt;
use App\Models\SchedulerHeartbeat;
use Illuminate\Console\Command;

class CheckCommunicationHealth extends Command
{
    protected $signature = 'communications:health {--stale-minutes=3}';

    protected $description = 'Fail when communication scheduling, inbound processing, or reconciliation needs operator attention.';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $heartbeat = SchedulerHeartbeat::query()->find('reservation-milestones');
        $stale = $heartbeat === null || $heartbeat->last_seen_at->isBefore(now()->subMinutes($staleMinutes));
        $stranded = CommunicationDeliveryEvent::withoutGlobalScopes()
            ->whereNull('processed_at')->whereIn('processing_state', ['pending', 'failed'])
            ->where('received_at', '<=', now()->subMinutes(5))->count();
        $uncertain = DeliveryAttempt::withoutGlobalScopes()->where('status', 'outcome_uncertain')
            ->where('reconcile_after', '<=', now())->count();

        if ($stale || $stranded > 0 || $uncertain > 0) {
            $this->error('Communication health alert: scheduler_stale='.(int) $stale.", stranded_events={$stranded}, expired_uncertain={$uncertain}.");

            return self::FAILURE;
        }

        $this->info('Communication scheduling and delivery processing are healthy.');

        return self::SUCCESS;
    }
}
