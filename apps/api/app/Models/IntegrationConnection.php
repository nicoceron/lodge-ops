<?php

namespace App\Models;

class IntegrationConnection extends TenantModel
{
    protected function casts(): array
    {
        return ['configuration' => 'array', 'last_synced_at' => 'immutable_datetime'];
    }
}
