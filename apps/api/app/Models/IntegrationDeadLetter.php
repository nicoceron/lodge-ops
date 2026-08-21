<?php

namespace App\Models;

use App\Services\Integrations\SafeIntegrationError;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string|null $integration_sync_run_item_id
 * @property string|null $integration_event_id
 * @property string $status
 * @property int $replay_count
 * @property-read IntegrationConnection $connection
 * @property-read IntegrationSyncRunItem|null $item
 * @property-read IntegrationEvent|null $event
 */
class IntegrationDeadLetter extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(fn (IntegrationDeadLetter $letter) => $letter->safe_error = SafeIntegrationError::from($letter->safe_error));
    }

    protected function casts(): array
    {
        return ['replay_count' => 'integer', 'last_replayed_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<IntegrationSyncRunItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRunItem::class, 'integration_sync_run_item_id');
    }

    /** @return BelongsTo<IntegrationEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'integration_event_id');
    }
}
