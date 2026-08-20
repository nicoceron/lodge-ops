<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $property_id
 * @property string|null $program_id
 * @property string $name
 * @property string $role
 * @property string $state
 * @property int $latest_version
 * @property-read Collection<int, ChecklistTemplateVersion> $versions
 */
class ChecklistTemplate extends TenantModel
{
    protected function casts(): array
    {
        return ['latest_version' => 'integer'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return HasMany<ChecklistTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ChecklistTemplateVersion::class)->orderByDesc('version');
    }
}
