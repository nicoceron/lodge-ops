<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $assignee_role
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property int $due_offset_minutes
 * @property bool $is_active
 */
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
