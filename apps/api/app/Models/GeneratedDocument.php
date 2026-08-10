<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GeneratedDocument extends TenantModel
{
    protected function casts(): array
    {
        return ['signed_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Generated documents are immutable. Generate a replacement instead.'));
        static::deleting(fn () => throw new LogicException('Generated documents cannot be deleted from the audit record.'));
    }
}
