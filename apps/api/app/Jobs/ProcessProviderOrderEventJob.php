<?php

namespace App\Jobs;

use App\Models\ProviderEvent;
use App\Models\Tenant;
use App\Services\Payments\ProcessMercadoPagoOrderEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessProviderOrderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public function __construct(public readonly string $tenantId, public readonly string $eventId) {}

    public function handle(ProcessMercadoPagoOrderEvent $processor): void
    {
        app(TenantContext::class)->set(Tenant::query()->findOrFail($this->tenantId));
        $processor->handle(ProviderEvent::query()->findOrFail($this->eventId));
    }
}
