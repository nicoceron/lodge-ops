<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MembershipRole $role
 * @property string $tenant_id
 * @property int $user_id
 * @property string|null $property_id
 * @property bool $is_active
 * @property array<string, mixed>|null $calendar_preferences
 * @property-read User $user
 */
class Membership extends TenantModel
{
    protected function casts(): array
    {
        return ['role' => MembershipRole::class, 'is_active' => 'boolean', 'calendar_preferences' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
