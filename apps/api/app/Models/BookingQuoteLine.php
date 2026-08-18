<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $type
 * @property string $description
 * @property CarbonImmutable|null $service_on
 * @property int $quantity_thousandths
 * @property int $unit_amount_minor
 * @property int $net_amount_minor
 * @property int $tax_amount_minor
 * @property int $gross_amount_minor
 * @property array<string, mixed>|null $metadata
 */
class BookingQuoteLine extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Booking quote lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Booking quote lines are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'service_on' => 'immutable_date',
            'quantity_thousandths' => 'integer',
            'unit_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'gross_amount_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(BookingQuote::class, 'booking_quote_id');
    }
}
