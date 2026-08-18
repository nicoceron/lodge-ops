<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id @property string $name @property string $code @property string $timezone @property string|null $address @property array<string, mixed>|null $settings */
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

    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    public function taxRules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }

    public function guestPortalDocuments(): HasMany
    {
        return $this->hasMany(GuestPortalDocument::class);
    }

    public function reportExports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }
}
