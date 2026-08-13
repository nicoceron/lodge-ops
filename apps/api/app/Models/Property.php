<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends TenantModel
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function resourceCategories(): HasMany
    {
        return $this->hasMany(ResourceCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function guestPortalDocuments(): HasMany
    {
        return $this->hasMany(GuestPortalDocument::class);
    }
}
