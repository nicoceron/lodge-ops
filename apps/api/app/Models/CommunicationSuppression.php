<?php

namespace App\Models;

class CommunicationSuppression extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $suppression): void {
            $suppression->scope_key = $suppression->property_id ?: '*';
        });
    }

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'lifted_at' => 'immutable_datetime',
        ];
    }
}
