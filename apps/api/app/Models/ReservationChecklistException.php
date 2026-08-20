<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $operation
 * @property string|null $title
 * @property string|null $description
 * @property string|null $priority
 * @property int|null $due_offset_minutes
 * @property int $sort_order
 */
class ReservationChecklistException extends TenantModel
{
    protected function casts(): array
    {
        return ['due_offset_minutes' => 'integer', 'sort_order' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class, 'checklist_template_item_id');
    }
}
