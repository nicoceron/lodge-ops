<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAttempt extends TenantModel
{
    protected function casts(): array
    {
        return ['attempt' => 'integer', 'attempted_at' => 'immutable_datetime'];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
