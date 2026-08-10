<?php

namespace App\Models;

class IdempotencyKey extends TenantModel
{
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
