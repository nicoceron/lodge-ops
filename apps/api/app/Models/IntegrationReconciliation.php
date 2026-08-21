<?php

namespace App\Models;

use App\Services\Integrations\IntegrationOperatorInputGuard;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_connection_id
 * @property string $status
 * @property-read IntegrationConnection $connection
 */
class IntegrationReconciliation extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $reconciliation): void {
            $reconciliation->safe_facts = app(IntegrationOperatorInputGuard::class)->admit($reconciliation->safe_facts ?? [], 'safe_facts');
            if ($reconciliation->resolution !== null) {
                $reconciliation->resolution = app(IntegrationOperatorInputGuard::class)->admit($reconciliation->resolution, 'resolution');
            }
        });
    }

    protected function casts(): array
    {
        return ['safe_facts' => 'array', 'resolved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
