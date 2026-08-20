<?php

namespace App\Models;

use App\Enums\BookingQuoteStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property BookingQuoteStatus $status
 * @property string $rate_plan_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $committed_at
 * @property array<string, mixed> $inputs
 * @property array<string, mixed>|null $deposit_policy_snapshot
 * @property array<string, mixed>|null $cancellation_policy_snapshot
 * @property array<string, mixed>|null $calculation_snapshot
 * @property-read Collection<int, BookingQuoteLine> $lines
 */
class BookingQuote extends TenantModel
{
    protected static function booted(): void
    {
        static::updating(function (BookingQuote $quote): void {
            $allowed = ['status', 'reservation_id', 'committed_at', 'updated_at'];
            if (array_diff(array_keys($quote->getDirty()), $allowed) !== []) {
                throw new LogicException('Booking quote price and policy snapshots are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Booking quotes are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'status' => BookingQuoteStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'infants' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'inputs' => 'array',
            'deposit_policy_snapshot' => 'array',
            'cancellation_policy_snapshot' => 'array',
            'calculation_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function resourceCategory(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return HasMany<BookingQuoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BookingQuoteLine::class)->orderBy('service_on')->orderBy('created_at');
    }
}
