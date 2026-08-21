<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\ProviderRefund;
use App\Models\Tenant;
use App\Services\Payments\ExecuteInPersonRefund;
use App\Services\Payments\ReconcileInPersonOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ReconcileInPersonPayments extends Command
{
    protected $signature = 'payments:reconcile-in-person {attempt? : Payment-attempt UUID} {--older-than=2} {--limit=100}';

    protected $description = 'Authoritatively reconcile active Point/QR Orders and processing Orders refunds.';

    public function handle(ReconcileInPersonOrder $orders, ExecuteInPersonRefund $refunds): int
    {
        $attemptId = $this->argument('attempt');
        $query = PaymentAttempt::withoutGlobalScopes()->whereNotNull('provider_order_id');
        if (is_string($attemptId) && $attemptId !== '') {
            $query->whereKey($attemptId);
        } else {
            $query->whereIn('state', ['creating', 'queued', 'at_terminal', 'action_required', 'processing'])
                ->where('updated_at', '<=', now()->subMinutes(max(1, (int) $this->option('older-than'))))
                ->limit(max(1, min(500, (int) $this->option('limit'))));
        }
        foreach ($query->orderBy('updated_at')->get() as $attempt) {
            app(TenantContext::class)->set(Tenant::query()->findOrFail($attempt->tenant_id));
            try {
                $result = $orders->handle($attempt);
                $this->line("{$result->id}: {$result->state->value}");
            } catch (\Throwable $exception) {
                $this->error("{$attempt->id}: {$exception->getMessage()}");
            }
        }

        if ($attemptId === null) {
            $pendingRefunds = ProviderRefund::withoutGlobalScopes()
                ->where('provider_resource_type', 'order')
                ->whereIn('state', ['requested', 'processing'])
                ->where('updated_at', '<=', now()->subMinutes(max(1, (int) $this->option('older-than'))))
                ->limit(max(1, min(500, (int) $this->option('limit'))))->get();
            foreach ($pendingRefunds as $refund) {
                app(TenantContext::class)->set(Tenant::query()->findOrFail($refund->tenant_id));
                try {
                    $result = $refunds->handle($refund->reservationChange, null);
                    $this->line("refund {$result->id}: {$result->state->value}");
                } catch (\Throwable $exception) {
                    $this->error("refund {$refund->id}: {$exception->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }
}
