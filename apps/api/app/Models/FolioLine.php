<?php

namespace App\Models;

use App\Enums\FolioLineType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $net_amount_minor
 * @property int $tax_amount_minor
 * @property int $gross_amount_minor
 * @property string $id
 * @property FolioLineType $type
 * @property string $description
 * @property string $quantity
 * @property CarbonImmutable $posted_at
 * @property string|null $reverses_folio_line_id
 * @property-read Reservation $reservation
 */
class FolioLine extends TenantModel
{
    protected function casts(): array
    {
        return [
            'type' => FolioLineType::class,
            'quantity' => 'decimal:3',
            'unit_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'gross_amount_minor' => 'integer',
            'amount_minor' => 'integer',
            'posted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
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
        static::creating(function (FolioLine $line): void {
            if (! array_key_exists('net_amount_minor', $line->getAttributes())) {
                $line->net_amount_minor = $line->amount_minor;
            }
            if (! array_key_exists('tax_amount_minor', $line->getAttributes())) {
                $line->tax_amount_minor = 0;
            }
            if (! array_key_exists('gross_amount_minor', $line->getAttributes())) {
                $line->gross_amount_minor = $line->amount_minor;
            }
            $line->amount_minor = $line->gross_amount_minor;
        });
        static::updating(fn () => throw new LogicException('Folio lines are append-only and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Folio lines are append-only and cannot be deleted.'));
    }
}
