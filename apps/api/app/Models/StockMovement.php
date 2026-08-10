<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost_minor' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(RetailSale::class, 'retail_sale_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only and cannot be deleted.'));
    }
}
