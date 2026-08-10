<?php

namespace App\Services\Automation;

use App\Models\Outbox;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class OutboxRecorder
{
    public function __construct(private readonly OutboxBatchPublisher $publisher) {}

    /** @param array<string, mixed> $payload */
    public function record(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        ?CarbonInterface $availableAt = null,
    ): Outbox {
        $message = Outbox::query()->create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
            'available_at' => $availableAt ?? now(),
        ]);

        $tenantId = $message->tenant_id;
        $messageId = $message->id;

        DB::afterCommit(fn () => $this->publisher->publishOne($tenantId, $messageId));

        return $message;
    }
}
