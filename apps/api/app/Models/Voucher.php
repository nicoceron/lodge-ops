<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $commercial_promotion_id
 * @property string|null $property_id
 * @property string $state
 * @property string $public_label
 * @property int|null $usage_limit
 * @property int|null $per_guest_limit
 * @property int|null $per_session_limit
 * @property int|null $budget_minor
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_until
 * @property-read CommercialPromotion $promotion
 */
class Voucher extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $voucher): void {
            $allowed = ['state', 'valid_until', 'updated_at'];
            if (array_diff(array_keys($voucher->getDirty()), $allowed) !== []) {
                throw new LogicException('Voucher identity and commercial limits are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Vouchers and their redemption history are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'usage_limit' => 'integer',
            'per_guest_limit' => 'integer',
            'per_session_limit' => 'integer',
            'budget_minor' => 'integer',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<CommercialPromotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(CommercialPromotion::class, 'commercial_promotion_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }
}
