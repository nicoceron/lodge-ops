<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConnectionCapability extends TenantModel
{
    protected function casts(): array
    {
        return ['configuration' => 'array', 'configuration_version' => 'integer', 'last_success_at' => 'immutable_datetime', 'last_error_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
