<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCommunicationDeliveryEvent;
use App\Models\CommunicationDeliveryEvent;
use Illuminate\Console\Command;
use Throwable;

class SweepCommunicationDeliveryEvents extends Command
{
    protected $signature = 'communications:sweep-delivery-events {--batch=100}';

    protected $description = 'Re-enqueue durable communication delivery events left pending or failed.';

    public function handle(): int
    {
        $batch = max(1, min(500, (int) $this->option('batch')));
        $events = CommunicationDeliveryEvent::withoutGlobalScopes()
            ->whereNull('processed_at')->whereIn('processing_state', ['pending', 'failed'])
            ->orderBy('received_at')->limit($batch)->get(['id', 'tenant_id']);
        $enqueued = 0;

        foreach ($events as $event) {
            try {
                ProcessCommunicationDeliveryEvent::dispatch($event->tenant_id, $event->id)
                    ->onQueue((string) config('communications.provider.event_queue', 'provider-events'));
                $enqueued++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info("Enqueued {$enqueued} durable delivery event(s).");

        return self::SUCCESS;
    }
}
