<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string|null $property_id
 * @property string $capability
 * @property string $direction
 * @property array<string, mixed>|null $checkpoint
 * @property int $version
 */
class IntegrationSyncCursor extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $cursor): void {
            $cursor->property_scope_key = $cursor->property_id ?: '00000000-0000-0000-0000-000000000000';
        });
    }

    protected function casts(): array
    {
        return ['checkpoint' => 'array', 'version' => 'integer', 'committed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
