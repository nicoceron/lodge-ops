<?php

namespace App\Jobs;

use App\Models\Communication;
use App\Models\CommunicationDeliveryEvent;
use App\Models\CommunicationSuppression;
use App\Models\DeliveryAttempt;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessCommunicationDeliveryEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly string $tenantId, public readonly string $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(TenantContext $tenantContext): void
    {
        $previousTenant = $tenantContext->check() ? $tenantContext->tenant() : null;
        $previousMembership = $tenantContext->membership();
        $tenantContext->clear();

        try {
            $tenantContext->set(Tenant::query()->findOrFail($this->tenantId));
            DB::transaction(function (): void {
                $event = CommunicationDeliveryEvent::query()->whereKey($this->eventId)->lockForUpdate()->firstOrFail();
                if ($event->processed_at !== null) {
                    return;
                }

                $attempt = DeliveryAttempt::query()
                    ->where('communication_provider_connection_id', $event->communication_provider_connection_id)
                    ->where('provider_account_id', $event->provider_account_id)
                    ->where('provider_message_id', $event->provider_message_id)
                    ->lockForUpdate()->first();
                if ($attempt === null) {
                    $event->forceFill([
                        'processing_state' => 'reconciliation_required',
                        'processing_error' => 'No matching delivery attempt.',
                        'processed_at' => now(),
                    ])->save();

                    return;
                }

                $communication = Communication::query()->whereKey($attempt->communication_id)->lockForUpdate()->firstOrFail();
                $status = $this->status($event);
                $this->applyStatus($attempt, $communication, $status, $event);
                $event->forceFill([
                    'delivery_attempt_id' => $attempt->id,
                    'processing_state' => 'processed',
                    'processing_error' => null,
                    'processed_at' => now(),
                ])->save();
            }, 3);
        } finally {
            $tenantContext->clear();
            if ($previousTenant !== null) {
                $tenantContext->set($previousTenant, $previousMembership);
            }
        }
    }

    private function status(CommunicationDeliveryEvent $event): string
    {
        return match ($event->type) {
            'email.sent' => 'sent',
            'email.delivered' => 'delivered',
            'email.delivery_delayed' => 'delayed',
            'email.complained' => 'complained',
            'email.suppressed' => 'suppressed',
            'email.bounced' => mb_strtolower((string) data_get($event->normalized_payload, 'bounce_type')) === 'transient'
                ? 'soft_bounced' : 'hard_bounced',
            'email.failed' => 'rejected',
            default => 'ignored',
        };
    }

    private function applyStatus(DeliveryAttempt $attempt, Communication $communication, string $status, CommunicationDeliveryEvent $event): void
    {
        if ($status === 'ignored') {
            return;
        }

        $terminal = ['complained', 'suppressed', 'hard_bounced'];
        $rank = ['queued' => 0, 'sending' => 1, 'provider_accepted' => 2, 'sent' => 3, 'delayed' => 3, 'soft_bounced' => 3, 'delivered' => 4, 'rejected' => 4, 'hard_bounced' => 5, 'suppressed' => 5, 'complained' => 6];
        $current = (string) $communication->status;
        if (! in_array($status, $terminal, true) && ($rank[$status] ?? 0) < ($rank[$current] ?? 0)) {
            return;
        }

        $occurredAt = $event->occurred_at;
        $fields = ['status' => $status];
        if ($status === 'sent') {
            $fields['sent_at'] = $occurredAt;
        } elseif ($status === 'delivered') {
            $fields['sent_at'] = $communication->sent_at ?? $occurredAt;
            $fields['delivered_at'] = $occurredAt;
        } elseif (in_array($status, ['rejected', 'hard_bounced'], true)) {
            $fields['failed_at'] = $occurredAt;
        }
        $attempt->forceFill($fields)->save();
        $communication->forceFill($fields)->save();

        if (in_array($status, ['hard_bounced', 'complained', 'suppressed'], true)) {
            foreach (data_get($event->normalized_payload, 'recipient_hashes', []) as $recipientHash) {
                CommunicationSuppression::query()->updateOrCreate(
                    ['channel' => 'email', 'recipient_hash' => $recipientHash],
                    [
                        'property_id' => $communication->property_id,
                        'reason' => $status === 'hard_bounced' ? 'bounce' : ($status === 'complained' ? 'complaint' : 'provider_suppression'),
                        'source' => 'provider_event',
                        'provider_event_id' => $event->provider_event_id,
                        'suppressed_at' => $occurredAt,
                        'expires_at' => null,
                        'lifted_at' => null,
                    ],
                );
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        CommunicationDeliveryEvent::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)->whereKey($this->eventId)
            ->whereNull('processed_at')->update([
                'processing_state' => 'failed',
                'processing_error' => 'Delivery event processing failed.',
            ]);
    }
}
