<?php

namespace App\Models;

use App\Enums\FolioLineType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class FolioLine extends TenantModel
{
    protected function casts(): array
    {
        return ['type' => FolioLineType::class, 'quantity' => 'decimal:3', 'unit_amount_minor' => 'integer', 'amount_minor' => 'integer', 'posted_at' => 'immutable_datetime', 'metadata' => 'array'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_folio_line_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_folio_line_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Folio lines are append-only and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Folio lines are append-only and cannot be deleted.'));
    }
}
