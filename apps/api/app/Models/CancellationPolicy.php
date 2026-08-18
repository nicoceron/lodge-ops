<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CancellationPolicy extends TenantModel
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return HasMany<CancellationPolicyTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(CancellationPolicyTier::class)->orderByDesc('days_before_arrival');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $this->loadMissing('tiers');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'summary' => $this->summary,
            'tiers' => $this->tiers->map(fn (CancellationPolicyTier $tier): array => [
                'days_before_arrival' => $tier->days_before_arrival,
                'retained_basis_points' => $tier->retained_basis_points,
                'minimum_fee_minor' => $tier->minimum_fee_minor,
            ])->values()->all(),
        ];
    }
}
