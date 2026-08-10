<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MessageTemplateVersion extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if ($version->getOriginal('published_at') !== null && $version->isDirty(['subject', 'body', 'language'])) {
                throw new LogicException('Published message template versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->published_at !== null) {
                throw new LogicException('Published message template versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
