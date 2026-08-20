<?php

namespace App\Jobs;

use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Models\IntegrationSyncRunItem;
use App\Models\Tenant;
use App\Services\Integrations\IntegrationRunService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessIntegrationRunItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 90;

    public function __construct(public readonly string $tenantId, public readonly string $itemId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(IntegrationRunService $runs, TenantContext $context): void
    {
        $context->clear();
        $context->set(Tenant::query()->findOrFail($this->tenantId));
        try {
            $runs->processItem(IntegrationSyncRunItem::query()->findOrFail($this->itemId));
        } catch (RateLimitedIntegrationException $exception) {
            $this->release($exception->retryAfterSeconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $context->set(Tenant::query()->findOrFail($this->tenantId));
        $item = IntegrationSyncRunItem::query()->find($this->itemId);
        if ($item !== null && $item->status !== 'succeeded' && $item->status !== 'dead_letter') {
            app(IntegrationRunService::class)->deadLetter($item, 'retry_exhausted', $exception ?? 'Integration item retry exhausted.');
        }
    }
}
