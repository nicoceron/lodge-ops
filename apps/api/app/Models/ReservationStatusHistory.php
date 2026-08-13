<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ReservationStatus|null $from_status
 * @property ReservationStatus $to_status
 * @property CarbonImmutable $changed_at
 * @property-read User|null $actor
 */
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
