<?php

namespace App\Jobs;

use App\Models\Outbox;
use App\Models\Tenant;
use App\Services\Automation\AutomationEngine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishOutboxMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $messageId,
        public readonly string $claimToken,
    ) {
        $this->onQueue('automations');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(AutomationEngine $engine, TenantContext $tenantContext): void
    {
        $previousTenant = $tenantContext->check() ? $tenantContext->tenant() : null;
        $previousMembership = $tenantContext->membership();
        $tenantContext->clear();

        try {
            $tenant = Tenant::query()->where('is_active', true)->find($this->tenantId);

            if ($tenant === null) {
                Outbox::withoutGlobalScopes()
                    ->whereKey($this->messageId)
                    ->where('tenant_id', $this->tenantId)
                    ->where('claim_token', $this->claimToken)
                    ->update([
                        'claim_token' => null,
                        'claimed_at' => null,
                        'attempts' => DB::raw('attempts + 1'),
                        'last_error' => 'Tenant is missing or inactive.',
                        'available_at' => now()->addMinutes(5),
                    ]);

                return;
            }

            $tenantContext->set($tenant);
            $message = Outbox::query()
                ->whereKey($this->messageId)
                ->where('claim_token', $this->claimToken)
                ->whereNull('published_at')
                ->first();

            if ($message === null) {
                return;
            }

            Outbox::query()->whereKey($message->id)->increment('attempts');

            try {
                DB::transaction(function () use ($engine, $message): void {
                    $engine->handle($message);

                    Outbox::query()
                        ->whereKey($message->id)
                        ->where('claim_token', $this->claimToken)
                        ->update([
                            'published_at' => now(),
                            'claim_token' => null,
                            'claimed_at' => null,
                            'last_error' => null,
                        ]);
                }, 3);
            } catch (Throwable $exception) {
                Outbox::query()
                    ->whereKey($message->id)
                    ->where('claim_token', $this->claimToken)
                    ->update([
                        'last_error' => $exception->getMessage(),
                        'available_at' => now()->addMinute(),
                    ]);

                throw $exception;
            }
        } finally {
            $tenantContext->clear();

            if ($previousTenant !== null) {
                $tenantContext->set($previousTenant, $previousMembership);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Outbox::withoutGlobalScopes()
            ->whereKey($this->messageId)
            ->where('tenant_id', $this->tenantId)
            ->where('claim_token', $this->claimToken)
            ->update([
                'claim_token' => null,
                'claimed_at' => null,
                'last_error' => $exception?->getMessage() ?? 'Outbox delivery failed.',
                'available_at' => now()->addMinutes(5),
            ]);
    }
}
