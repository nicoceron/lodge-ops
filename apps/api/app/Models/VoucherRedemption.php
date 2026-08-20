<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $booking_quote_id
 * @property string $voucher_id
 * @property string $state
 * @property string $currency
 * @property int $discount_minor
 * @property string|null $session_key_hash
 * @property-read Voucher $voucher
 * @property-read Collection<int, VoucherRedemptionEvent> $events
 */
class VoucherRedemption extends TenantModel
{
    protected function casts(): array
    {
        return [
            'discount_minor' => 'integer',
            'reserved_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Voucher, $this> */
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

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return HasMany<VoucherRedemptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(VoucherRedemptionEvent::class)->orderBy('occurred_at');
    }
}
