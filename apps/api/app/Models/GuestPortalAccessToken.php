<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestPortalAccessToken extends TenantModel
{
    protected $hidden = ['token_hash', 'session_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'exchanged_at' => 'immutable_datetime',
            'session_expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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
