<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property int $due_offset_minutes
 * @property int $sort_order
 */
class ChecklistTemplateItem extends TenantModel
{
    protected function casts(): array
    {
        return ['due_offset_minutes' => 'integer', 'sort_order' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateVersion::class, 'checklist_template_version_id');
    }
}
