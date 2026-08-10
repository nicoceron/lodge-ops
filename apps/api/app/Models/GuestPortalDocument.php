<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestPortalDocument extends TenantModel
{
    protected static function booted(): void
    {
        static::saving(function (self $document): void {
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
