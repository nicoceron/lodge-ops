<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SettlementVarianceAction extends TenantModel
{
    protected function casts(): array
    {
        return ['acted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement variance actions are append-only.'));
        static::deleting(fn () => throw new LogicException('Settlement variance actions cannot be deleted.'));
    }

    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(SettlementEntry::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
