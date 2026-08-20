<?php

namespace App\Console\Commands;

use App\Models\ProviderRefund;
use App\Models\Tenant;
use App\Services\Payments\RecoverProviderRefund;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

class RecoverStuckProviderRefunds extends Command
{
    protected $signature = 'payments:recover-refunds {--older-than=15} {--limit=100}';

    protected $description = 'Recover stuck processing refunds that have a known provider refund identity.';

    public function handle(RecoverProviderRefund $recovery): int
    {
        $processed = 0;
        $failed = 0;
        foreach (Tenant::query()->cursor() as $tenant) {
            app(TenantContext::class)->set($tenant);
            ProviderRefund::query()
                ->where('state', 'processing')
                ->whereNotNull('provider_refund_id')
                ->where('last_attempted_at', '<=', now()->subMinutes(max(1, (int) $this->option('older-than'))))
                ->orderBy('last_attempted_at')
                ->limit(max(1, (int) $this->option('limit')))
                ->get()
                ->each(function (ProviderRefund $refund) use ($recovery, &$processed, &$failed): void {
                    try {
                        $recovery->handle($refund, $refund->provider_refund_id, null);
                        $processed++;
                    } catch (Throwable $exception) {
                        $refund->update(['last_error' => str($exception->getMessage())->limit(500)]);
                        $failed++;
                    }
                });
        }
        $this->info("Recovered {$processed}; failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
