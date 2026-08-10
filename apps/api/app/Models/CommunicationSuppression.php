<?php

namespace App\Models;

class CommunicationSuppression extends TenantModel
{
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
