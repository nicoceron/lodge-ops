<?php

namespace App\Models;

use App\Enums\PaymentRequestPurpose;
use App\Enums\PaymentRequestState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $reservation_id
 * @property string|null $deposit_id
 * @property PaymentRequestPurpose $purpose
 * @property PaymentRequestState $state
 * @property int $source_amount_minor
 * @property string $source_currency
 * @property string|null $charge_currency
 * @property string $access_token_hash
 * @property int $access_count
 * @property string|null $payment_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $created_at
 * @property-read Reservation $reservation
 * @property-read Deposit|null $deposit
 */
class PaymentRequest extends TenantModel
{
    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentRequestPurpose::class,
            'state' => PaymentRequestState::class,
            'source_amount_minor' => 'integer',
            'calculation_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'access_count' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
