<?php

namespace App\Models;

use App\Services\Integrations\SafeIntegrationError;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConnectionCapability extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $capability): void {
            if ($capability->last_error !== null) {
                $capability->last_error = SafeIntegrationError::from($capability->last_error);
            }
        });
    }

    protected function casts(): array
    {
        return ['configuration' => 'array', 'configuration_version' => 'integer', 'last_success_at' => 'immutable_datetime', 'last_error_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
