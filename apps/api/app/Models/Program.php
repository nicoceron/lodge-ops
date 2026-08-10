<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends TenantModel
{
    protected function casts(): array
    {
        return ['default_duration_minutes' => 'integer', 'capacity' => 'integer', 'is_active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ServiceOccurrence::class);
    }
}
