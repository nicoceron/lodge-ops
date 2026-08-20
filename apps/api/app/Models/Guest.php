<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $merged_into_id
 * @property array<string, mixed>|null $preferences
 */
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

    public function companionReservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guests')
            ->using(ReservationGuest::class)
            ->withPivot(['id', 'tenant_id', 'role'])
            ->withTimestamps();
    }

    public function stays(): HasMany
    {
        $guestId = $this->getKey();

        return $this->hasMany(Reservation::class, 'primary_guest_id')
            ->orWhereHas('guests', fn ($query) => $query->whereKey($guestId));
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
    }

    public function documentGenerationRequests(): HasMany
    {
        return $this->hasMany(DocumentGenerationRequest::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
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
