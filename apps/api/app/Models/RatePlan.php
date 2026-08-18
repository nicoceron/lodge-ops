<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $active_from
 * @property CarbonImmutable|null $active_until
 * @property-read DepositPolicy|null $depositPolicy
 * @property-read CancellationPolicy|null $cancellationPolicy
 */
class RatePlan extends TenantModel
{
    protected function casts(): array
    {
        return [
            'active_from' => 'immutable_date',
            'active_until' => 'immutable_date',
            'minimum_occupancy' => 'integer',
            'maximum_occupancy' => 'integer',
            'inclusions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<DepositPolicy, $this> */
    public function depositPolicy(): BelongsTo
    {
        return $this->belongsTo(DepositPolicy::class);
    }

    /** @return BelongsTo<CancellationPolicy, $this> */
    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    /** @return HasMany<RateRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(RateRule::class)->orderByDesc('priority');
    }
}
