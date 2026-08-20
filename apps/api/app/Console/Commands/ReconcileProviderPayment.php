<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Payments\ReconcileProviderPayment as ReconcileCommand;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ReconcileProviderPayment extends Command
{
    protected $signature = 'payments:reconcile {attempt : Payment-attempt UUID}';

    protected $description = 'Fetch and process one known provider payment through the normal exact-once path.';

    public function handle(ReconcileCommand $command): int
    {
        $attempt = PaymentAttempt::withoutGlobalScopes()->findOrFail($this->argument('attempt'));
        app(TenantContext::class)->set(Tenant::query()->findOrFail($attempt->tenant_id));
        $event = $command->handle($attempt);
        $this->info("Reconciliation event {$event->id}: {$event->processing_state->value}");

        return self::SUCCESS;
    }
}
