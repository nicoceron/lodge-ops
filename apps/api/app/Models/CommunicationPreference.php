<?php

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property bool $is_allowed
 * @property string $purpose
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $withdrawn_at
 */
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
