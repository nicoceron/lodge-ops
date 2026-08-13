<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $profile
 * @property array<string, mixed> $travel
 * @property array<string, mixed> $preferences
 */
class GuestPortalProfile extends TenantModel
{
    protected function casts(): array
    {
        return [
            'profile' => 'array',
            'travel' => 'array',
            'preferences' => 'array',
            'consented_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
