<?php

namespace App\Models;

use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $reservation_id
 * @property PaymentStatus $status
 * @property string $method
 * @property PaymentOrigin $origin
 * @property CarbonImmutable|null $processed_at
 * @property string $currency
 * @property int $amount_minor
 * @property-read Reservation $reservation
 */
class Payment extends TenantModel
{
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'origin' => PaymentOrigin::class,
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function documentGenerationRequests(): HasMany
    {
        return $this->hasMany(DocumentGenerationRequest::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    /** @return HasMany<FolioLine, $this> */
    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
