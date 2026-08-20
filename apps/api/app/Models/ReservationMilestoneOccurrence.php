<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $reservation_id
 * @property string $key
 * @property int $reservation_revision
 * @property string $rule_version
 * @property string $policy_version
 * @property string $timezone
 * @property string $state
 * @property int $attempts
 * @property CarbonImmutable $target_at
 */
class ReservationMilestoneOccurrence extends TenantModel
{
    protected function casts(): array
    {
        return [
            'reservation_revision' => 'integer',
            'target_local' => 'immutable_datetime',
            'target_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'dispatched_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
