<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class GeneratedDocument extends TenantModel
{
    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'template_version' => 'integer',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function generationRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationRequest::class, 'document_generation_request_id');
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservationChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_document_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Generated documents are immutable. Generate a replacement instead.'));
        static::deleting(fn () => throw new LogicException('Generated documents cannot be deleted from the audit record.'));
    }
}
