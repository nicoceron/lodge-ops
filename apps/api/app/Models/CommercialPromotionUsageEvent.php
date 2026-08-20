<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CommercialPromotionUsageEvent extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Promotion usage events are append-only.'));
        static::deleting(fn () => throw new LogicException('Promotion usage events are append-only.'));
    }

    protected function casts(): array
    {
        return ['facts' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(CommercialPromotionUsage::class, 'commercial_promotion_usage_id');
    }
}
