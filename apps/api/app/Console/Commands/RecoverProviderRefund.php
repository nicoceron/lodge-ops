<?php

namespace App\Console\Commands;

use App\Models\ProviderRefund;
use App\Models\Tenant;
use App\Services\Payments\RecoverProviderRefund as Recovery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

class RecoverProviderRefund extends Command
{
    protected $signature = 'payments:recover-refund {refund : Provider-refund UUID} {provider-refund-id : Provider dashboard/API refund ID} {--actor=}';

    protected $description = 'Authoritatively recover a provider-dashboard or ambiguous provider refund.';

    public function handle(Recovery $recovery): int
    {
        $refund = ProviderRefund::withoutGlobalScopes()->findOrFail($this->argument('refund'));
        app(TenantContext::class)->set(Tenant::query()->findOrFail($refund->tenant_id));
        $result = $recovery->handle(
            $refund,
            (string) $this->argument('provider-refund-id'),
            $this->option('actor') === null ? null : (int) $this->option('actor'),
        );
        $this->info("Provider refund {$result->id}: {$result->state->value}");

        return self::SUCCESS;
    }
}
