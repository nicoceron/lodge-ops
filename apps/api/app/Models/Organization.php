<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends TenantModel
{
    protected function casts(): array
    {
        return ['commission_basis_points' => 'integer', 'metadata' => 'array', 'is_active' => 'boolean'];
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
