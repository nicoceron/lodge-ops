<?php

namespace App\Models;

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
