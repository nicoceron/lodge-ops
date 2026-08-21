<?php

namespace App\Jobs;

use App\Enums\ProviderEventState;
use App\Models\ProviderEvent;
use App\Models\Tenant;
use App\Services\Payments\ProcessProviderEvent;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class ProcessProviderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly string $tenantId, public readonly string $eventId)
    {
        $this->onQueue('provider-events');
        $this->afterCommit();
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->toImmutable()->addMinutes(30);
    }

    public function handle(ProcessProviderEvent $processor): void
    {
        app(TenantContext::class)->set(Tenant::query()->findOrFail($this->tenantId));
        $processor->handle(ProviderEvent::query()->findOrFail($this->eventId));
    }

    public function failed(?Throwable $exception): void
    {
        ProviderEvent::withoutGlobalScopes()->whereKey($this->eventId)->update([
            'processing_state' => ProviderEventState::Failed->value,
            'last_error' => Str::limit($exception === null ? 'Provider event job exhausted retries.' : class_basename($exception).': '.$exception->getMessage(), 500),
        ]);
    }
}
