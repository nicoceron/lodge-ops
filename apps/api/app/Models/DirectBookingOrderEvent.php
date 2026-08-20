<?php

namespace App\Models;

use App\Enums\DirectBookingOrderState;
use App\Enums\DirectBookingTransitionAuthority;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DirectBookingOrderEvent extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Direct-booking transition events are immutable.'));
        static::deleting(fn () => throw new LogicException('Direct-booking transition events are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'from_state' => DirectBookingOrderState::class,
            'to_state' => DirectBookingOrderState::class,
            'authority' => DirectBookingTransitionAuthority::class,
            'state_version' => 'integer',
            'safe_metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DirectBookingOrder::class, 'direct_booking_order_id');
    }
}
