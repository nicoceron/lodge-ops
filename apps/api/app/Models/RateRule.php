<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property list<int>|null $weekdays
 * @property list<int>|null $allowed_arrival_days
 * @property list<array{minimum_guests?:int, adjustment_basis_points?:int}>|null $group_tiers
 * @property string|null $program_id
 * @property int|null $minimum_advance_days
 * @property int|null $maximum_advance_days
 * @property int|null $minimum_occupancy
 * @property int|null $maximum_occupancy
 * @property bool $buyout_only
 * @property bool $blackout
 * @property int|null $adult_amount_minor
 * @property int|null $child_amount_minor
 * @property int|null $infant_amount_minor
 * @property int $single_supplement_minor
 * @property int $length_of_stay_adjustment_basis_points
 * @property int $version
 */
class RateRule extends TenantModel
{
    protected static function booted(): void
    {
        $guard = function (self $rule): void {
            if (RatePlan::query()->whereKey($rule->rate_plan_id)->value('state') === 'published') {
                throw new LogicException('Published rate-plan rules are immutable; copy a new plan version.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'weekdays' => 'array',
            'amount_minor' => 'integer',
            'version' => 'integer',
            'minimum_advance_days' => 'integer',
            'maximum_advance_days' => 'integer',
            'allowed_arrival_days' => 'array',
            'blackout' => 'boolean',
            'minimum_occupancy' => 'integer',
            'maximum_occupancy' => 'integer',
            'buyout_only' => 'boolean',
            'adult_amount_minor' => 'integer',
            'child_amount_minor' => 'integer',
            'infant_amount_minor' => 'integer',
            'single_supplement_minor' => 'integer',
            'group_tiers' => 'array',
            'length_of_stay_adjustment_basis_points' => 'integer',
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

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
