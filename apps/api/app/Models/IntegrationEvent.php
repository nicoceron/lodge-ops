<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string|null $property_id
 * @property string $external_id
 * @property string $event_type
 * @property string $external_version
 * @property string $raw_checksum
 * @property string $disposition
 * @property int $attempt
 * @property-read IntegrationConnection $connection
 */
class IntegrationEvent extends TenantModel
{
    protected function casts(): array
    {
        return ['safe_snapshot' => 'array', 'attempt' => 'integer', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $event): void {
            if (array_diff(array_keys($event->getDirty()), ['disposition', 'attempt', 'processed_at', 'last_error', 'updated_at']) !== []) {
                throw new LogicException('Integration event facts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Integration events are immutable.'));
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
