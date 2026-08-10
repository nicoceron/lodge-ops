<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
