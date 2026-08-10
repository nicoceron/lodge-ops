<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends TenantModel
{
    protected function casts(): array
    {
        return ['preferences' => 'array', 'marketing_consent' => 'boolean'];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'primary_guest_id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
    }
}
