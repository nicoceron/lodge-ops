<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\IntegrationOperation;
use DomainException;

final class IntegrationOperationRecorder
{
    /** @param array<string, mixed>|null $facts */
    public static function record(IntegrationConnection $connection, string $operation, ?int $actorId, string $reason, ?array $facts = null, ?string $idempotencyKeyHash = null): IntegrationOperation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('An operational reason is required.');
        }

        return IntegrationOperation::query()->create([
            'integration_connection_id' => $connection->id,
            'actor_id' => $actorId,
            'operation' => $operation,
            'idempotency_key_hash' => $idempotencyKeyHash,
            'reason' => str($reason)->limit(500),
            'safe_facts' => $facts,
            'occurred_at' => now(),
        ]);
    }
}
