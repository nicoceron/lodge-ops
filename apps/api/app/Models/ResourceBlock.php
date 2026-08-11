<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property-read \App\Models\Resource $resource
 */
class ResourceBlock extends TenantModel
{
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }
}
