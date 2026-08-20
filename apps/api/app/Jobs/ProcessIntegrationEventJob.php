<?php

namespace App\Jobs;

use App\Exceptions\Integrations\RateLimitedIntegrationException;
use App\Models\IntegrationEvent;
use App\Models\Tenant;
use App\Services\Integrations\IntegrationEventService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessIntegrationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public readonly string $tenantId, public readonly string $eventId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(IntegrationEventService $events, TenantContext $context): void
    {
        $context->clear();
        $context->set(Tenant::query()->findOrFail($this->tenantId));
        try {
            $events->process(IntegrationEvent::query()->findOrFail($this->eventId));
        } catch (RateLimitedIntegrationException $exception) {
            $this->release($exception->retryAfterSeconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $context = app(TenantContext::class);
        $context->clear();
        $context->set(Tenant::query()->findOrFail($this->tenantId));
        $event = IntegrationEvent::query()->find($this->eventId);
        if ($event !== null && $event->disposition !== 'processed' && $event->disposition !== 'dead_letter') {
            app(IntegrationEventService::class)->deadLetter($event, $exception ?? 'Integration event retry exhausted.');
        }
    }
}
