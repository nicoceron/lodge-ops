<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property CarbonImmutable|null $active_from
 * @property CarbonImmutable|null $active_until
 * @property-read DepositPolicy|null $depositPolicy
 * @property-read CancellationPolicy|null $cancellationPolicy
 */
class RatePlan extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->getOriginal('state') === 'published') {
                $allowed = ['state', 'retired_at', 'is_active', 'updated_at'];
                if (array_diff(array_keys($plan->getDirty()), $allowed) !== []) {
                    throw new LogicException('Published rate plan versions are immutable; copy a new version.');
                }
            }
        });
        static::deleting(fn (self $plan) => $plan->state === 'draft'
            ?: throw new LogicException('Published rate plan versions cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'active_from' => 'immutable_date',
            'active_until' => 'immutable_date',
            'minimum_occupancy' => 'integer',
            'maximum_occupancy' => 'integer',
            'inclusions' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
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
        return $this->hasMany(RateRule::class)->orderByDesc('priority')->orderBy('id');
    }

    /** @return HasMany<RatePlanService, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(RatePlanService::class)->orderBy('priority')->orderBy('id');
    }
}
