<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class CommercialPromotionUsage extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $usage): void {
            $allowed = ['state', 'confirmed_at', 'released_at', 'superseded_by_id', 'updated_at'];
            if (array_diff(array_keys($usage->getDirty()), $allowed) !== []) {
                throw new LogicException('Promotion usage facts are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Promotion usage facts are immutable.'));
    }

    protected function casts(): array
    {
        return ['discount_minor' => 'integer', 'reserved_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CommercialPromotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(CommercialPromotion::class, 'commercial_promotion_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(BookingQuote::class, 'booking_quote_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommercialPromotionUsageEvent::class);
    }
}
