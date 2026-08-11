<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationAutomationMilestone extends TenantModel
{
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
