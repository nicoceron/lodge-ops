<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramTaskTemplate extends TenantModel
{
    protected function casts(): array
    {
        return [
            'due_offset_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OperationalTask::class);
    }
}
