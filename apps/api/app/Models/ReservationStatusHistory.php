<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationStatusHistory extends TenantModel
{
    protected function casts(): array
    {
        return [
            'from_status' => ReservationStatus::class,
            'to_status' => ReservationStatus::class,
            'changed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
