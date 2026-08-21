<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $checklist_template_id
 * @property int $version
 * @property string $state
 * @property-read ChecklistTemplate $template
 * @property-read Collection<int, ChecklistTemplateItem> $items
 */
class ChecklistTemplateVersion extends TenantModel
{
    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('state') === 'published'
                && array_diff(array_keys($version->getDirty()), ['state', 'retired_at', 'updated_at']) !== []) {
                throw new LogicException('Published checklist versions are immutable. Create a new version.');
            }
        });
        static::deleting(fn (self $version) => $version->state === 'published'
            ? throw new LogicException('Published checklist versions cannot be deleted.')
            : null);
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return HasMany<ChecklistTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class)->orderBy('sort_order');
    }
}
