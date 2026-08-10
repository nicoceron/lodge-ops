<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Survey extends TenantModel
{
    protected function casts(): array
    {
        return ['score' => 'integer', 'answers' => 'array', 'sent_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
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
