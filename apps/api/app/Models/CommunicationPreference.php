<?php

namespace App\Models;

class CommunicationPreference extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $preference): void {
            $preference->scope_key = ($preference->property_id ?: '*').':'.($preference->guest_id ?: '*');
        });
    }

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
