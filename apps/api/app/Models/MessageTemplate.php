<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplate extends TenantModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MessageTemplateVersion::class);
    }
}
