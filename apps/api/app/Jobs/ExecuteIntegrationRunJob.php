<?php

namespace App\Jobs;

use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Models\IntegrationSyncRun;
use App\Models\Tenant;
use App\Services\Integrations\IntegrationRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteIntegrationRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public readonly string $tenantId, public readonly string $runId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(IntegrationRunService $runs, TenantContext $context): void
    {
        $context->clear();
        $context->set(Tenant::query()->findOrFail($this->tenantId));
        try {
            $runs->executePage(IntegrationSyncRun::query()->findOrFail($this->runId));
        } catch (RateLimitedIntegrationException $exception) {
            $this->release($exception->retryAfterSeconds);
        }
    }
}
