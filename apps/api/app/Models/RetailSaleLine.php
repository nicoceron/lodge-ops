<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RetailSaleLine extends TenantModel
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_amount_minor' => 'integer', 'amount_minor' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(RetailSale::class, 'retail_sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    public function folioLine(): BelongsTo
    {
        return $this->belongsTo(FolioLine::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted retail sale lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted retail sale lines cannot be deleted.'));
    }
}
