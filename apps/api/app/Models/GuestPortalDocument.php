<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** @property string $id @property string $title @property string $body @property string $body_hash @property string $version */
class GuestPortalDocument extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if ($document->exists && $document->isDirty(['title', 'body', 'version']) && $document->acknowledgements()->exists()) {
                throw new LogicException('Acknowledged guest documents are immutable. Create a new version instead.');
            }
            $document->body_hash = hash('sha256', $document->body);
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(GuestPortalAcknowledgement::class, 'document_id');
    }
}
