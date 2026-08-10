<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends TenantModel
{
    protected function casts(): array
    {
        return ['preferences' => 'array', 'marketing_consent' => 'boolean', 'merged_at' => 'immutable_datetime'];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'primary_guest_id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
    }

    public function guestPortalProfiles(): HasMany
    {
        return $this->hasMany(GuestPortalProfile::class);
    }

    public function guestPortalAccessTokens(): HasMany
    {
        return $this->hasMany(GuestPortalAccessToken::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function mergeAliases(): HasMany
    {
        return $this->hasMany(GuestMergeAlias::class);
    }
}
