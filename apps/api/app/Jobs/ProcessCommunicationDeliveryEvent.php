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

        $occurredAt = $event->occurred_at;
        $precedence = $this->precedence($status);
        $facts = [];
        if ($status === 'sent') {
            $facts['sent_at'] = $communication->sent_at === null || $occurredAt->isBefore($communication->sent_at)
                ? $occurredAt : $communication->sent_at;
        } elseif ($status === 'delivered') {
            $facts['sent_at'] = $communication->sent_at ?? $occurredAt;
            $facts['delivered_at'] = $communication->delivered_at === null || $occurredAt->isBefore($communication->delivered_at)
                ? $occurredAt : $communication->delivered_at;
        } elseif (in_array($status, ['rejected', 'hard_bounced'], true)) {
            $facts['failed_at'] = $communication->failed_at === null || $occurredAt->isBefore($communication->failed_at)
                ? $occurredAt : $communication->failed_at;
        }

        if ($facts !== []) {
            $attempt->forceFill($facts)->save();
            $communication->forceFill($facts)->save();
        }

        $currentOccurredAt = $communication->status_occurred_at;
        $currentPrecedence = max(
            (int) $communication->status_precedence,
            $this->precedence((string) $communication->status),
        );
        $mayAdvance = $currentOccurredAt === null
            || $occurredAt->isAfter($currentOccurredAt)
            || ($occurredAt->equalTo($currentOccurredAt) && $precedence > $currentPrecedence);
        if ($mayAdvance && $precedence >= $currentPrecedence) {
            $fields = [
                'status' => $status,
                'status_occurred_at' => $occurredAt,
                'status_precedence' => $precedence,
            ];
            $attempt->forceFill($fields)->save();
            $communication->forceFill($fields)->save();
        }

        if (in_array($status, ['hard_bounced', 'complained', 'suppressed'], true)) {
            foreach (data_get($event->normalized_payload, 'recipient_hashes', []) as $recipientHash) {
                CommunicationSuppression::query()->updateOrCreate(
                    [
                        'scope_key' => $communication->property_id ?: '*',
                        'channel' => 'email',
                        'recipient_hash' => $recipientHash,
                    ],
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

    private function precedence(string $status): int
    {
        return match ($status) {
            'queued' => 0,
            'sending' => 5,
            'provider_accepted' => 8,
            'sent' => 10,
            'delayed' => 20,
            'soft_bounced' => 30,
            'rejected' => 35,
            'delivered' => 40,
            'hard_bounced' => 60,
            'suppressed' => 70,
            'complained' => 80,
            default => 0,
        };
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
