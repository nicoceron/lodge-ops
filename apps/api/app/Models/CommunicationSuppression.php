<?php

namespace App\Models;

class CommunicationSuppression extends TenantModel
{
    protected function casts(): array
    {
        return [
            'suppressed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'lifted_at' => 'immutable_datetime',
        ];
    }
}
