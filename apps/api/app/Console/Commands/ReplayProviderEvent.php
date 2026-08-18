<?php

namespace App\Console\Commands;

use App\Enums\ProviderEventState;
use App\Models\ProviderEvent;
use App\Models\Tenant;
use App\Services\Payments\ProcessProviderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ReplayProviderEvent extends Command
{
    protected $signature = 'payments:replay-event {event : Provider-event UUID} {--reason=}';

    protected $description = 'Replay a failed or mismatched provider event after an operator investigation.';

    public function handle(ProcessProviderEvent $processor): int
    {
        if (trim((string) $this->option('reason')) === '') {
            $this->error('A bounded investigation reason is required.');

            return self::INVALID;
        }
        $event = ProviderEvent::withoutGlobalScopes()->findOrFail($this->argument('event'));
        app(TenantContext::class)->set(Tenant::query()->findOrFail($event->tenant_id));
        if (! in_array($event->processing_state, [ProviderEventState::Failed, ProviderEventState::Mismatched], true)) {
            $this->error('Only failed or mismatched events may be replayed.');

            return self::INVALID;
        }
        $event->update([
            'processing_state' => ProviderEventState::Received,
            'last_error' => 'Replay authorized: '.str((string) $this->option('reason'))->limit(450),
            'processed_at' => null,
        ]);
        $processed = $processor->handle($event->fresh());
        $this->info("Provider event {$processed->id}: {$processed->processing_state->value}");

        return self::SUCCESS;
    }
}
