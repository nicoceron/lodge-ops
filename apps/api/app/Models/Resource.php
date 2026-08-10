<?php

namespace App\Models;

use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends TenantModel
{
    protected function casts(): array
    {
        return ['type' => ResourceType::class, 'capacity' => 'integer', 'attributes' => 'array', 'is_active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(ResourceBlock::class);
    }
}
