<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEndpointKey extends TenantModel
{
    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'valid_from' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
