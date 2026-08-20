<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $type
 * @property string|null $policy_reason
 * @property array<string, mixed> $facts
 * @property CarbonImmutable $occurred_at
 */
class VoucherRedemptionEvent extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Voucher redemption events are append-only.'));
        static::deleting(fn () => throw new LogicException('Voucher redemption events are append-only.'));
    }

    protected function casts(): array
    {
        return ['facts' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function redemption(): BelongsTo
    {
        return $this->belongsTo(VoucherRedemption::class, 'voucher_redemption_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
