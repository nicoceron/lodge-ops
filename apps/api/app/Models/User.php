<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function guideResources()
    {
        return $this->hasMany(Resource::class);
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'memberships')
            ->withPivot(['property_id', 'role', 'is_active'])
            ->wherePivot('is_active', true)
            ->withTimestamps();
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->tenants()->where('tenants.is_active', true)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Tenant && $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->email_verified_at !== null && $this->tenants()->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
