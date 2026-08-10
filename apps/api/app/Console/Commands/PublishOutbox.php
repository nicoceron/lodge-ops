<?php

namespace App\Console\Commands;

use App\Services\Automation\OutboxBatchPublisher;
use Illuminate\Console\Command;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--batch=100 : Maximum messages to claim}';

    protected $description = 'Claim pending domain events and queue tenant-aware automation jobs';

    public function handle(OutboxBatchPublisher $publisher): int
    {
        $published = $publisher->publish((int) $this->option('batch'));

        $this->info("Queued {$published} outbox message(s).");

        return self::SUCCESS;
    }
}
