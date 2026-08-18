<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property list<int>|null $weekdays
 */
class RateRule extends TenantModel
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'weekdays' => 'array',
            'amount_minor' => 'integer',
            'minimum_stay' => 'integer',
            'maximum_stay' => 'integer',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'stop_sell' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function resourceCategory(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class);
    }
}
