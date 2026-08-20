<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string|null $property_id
 * @property string $capability
 * @property string $direction
 * @property string $trigger
 * @property string $status
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property array<string, mixed>|null $starting_checkpoint
 * @property array<string, mixed>|null $pending_checkpoint
 * @property bool $pending_has_more
 * @property bool $page_in_progress
 * @property int $page_number
 * @property int $attempt
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $started_at
 * @property-read IntegrationConnection $connection
 */
class IntegrationSyncRun extends TenantModel
{
    protected function casts(): array
    {
        return [
            'starting_checkpoint' => 'array', 'pending_checkpoint' => 'array', 'pending_has_more' => 'boolean', 'page_in_progress' => 'boolean',
            'page_number' => 'integer', 'attempt' => 'integer', 'item_count' => 'integer', 'success_count' => 'integer',
            'error_count' => 'integer', 'dead_letter_count' => 'integer', 'claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return HasMany<IntegrationSyncRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(IntegrationSyncRunItem::class);
    }
}
