<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends TenantModel
{
    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
