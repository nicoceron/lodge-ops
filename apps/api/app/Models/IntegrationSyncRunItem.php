<?php

namespace App\Models;

use App\Services\Integrations\SafeIntegrationError;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_sync_run_id
 * @property string|null $property_id
 * @property int $page_number
 * @property string $external_key
 * @property string $payload_checksum
 * @property string $status
 * @property int $attempt
 * @property string $idempotency_key
 * @property-read IntegrationSyncRun $run
 */
class IntegrationSyncRunItem extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (IntegrationSyncRunItem $item): void {
            if ($item->last_error !== null) {
                $item->last_error = SafeIntegrationError::from($item->last_error);
            }
            if ($item->safe_payload !== null) {
                $item->safe_payload = SafeIntegrationError::value($item->safe_payload);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'safe_payload' => 'array', 'page_number' => 'integer', 'attempt' => 'integer', 'http_status' => 'integer',
            'latency_ms' => 'integer', 'available_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<IntegrationSyncRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(IntegrationSyncRun::class, 'integration_sync_run_id');
    }
}
