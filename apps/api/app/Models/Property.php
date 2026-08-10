<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends TenantModel
{
    protected function casts(): array
    {
        return ['settings' => 'array', 'is_active' => 'boolean'];
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
