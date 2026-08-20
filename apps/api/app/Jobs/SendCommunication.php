<?php

namespace App\Jobs;

use App\Models\Communication;
use App\Models\Tenant;
use App\Services\CommunicationDeliveryService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCommunication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public readonly string $tenantId, public readonly string $communicationId)
    {
        $this->onQueue((string) config('communications.provider.notification_queue', 'notifications'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(CommunicationDeliveryService $delivery, TenantContext $context): void
    {
        $previousTenant = $context->check() ? $context->tenant() : null;
        $previousMembership = $context->membership();
        $context->clear();

        try {
            $context->set(Tenant::query()->findOrFail($this->tenantId));
            $delivery->deliver(Communication::query()->findOrFail($this->communicationId));
        } finally {
            $context->clear();
            if ($previousTenant !== null) {
                $context->set($previousTenant, $previousMembership);
            }
        }
    }
}
