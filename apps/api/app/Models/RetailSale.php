<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RetailSale extends TenantModel
{
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RetailSaleLine::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted retail sales are immutable. Post a correcting transaction instead.'));
        static::deleting(fn () => throw new LogicException('Posted retail sales cannot be deleted.'));
    }
}
