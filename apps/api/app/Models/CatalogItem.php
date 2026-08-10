<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogItem extends TenantModel
{
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'cost_minor' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
