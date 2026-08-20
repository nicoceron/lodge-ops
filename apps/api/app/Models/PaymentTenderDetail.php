<?php

namespace App\Models;

use App\Enums\PaymentChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $reservation_id
 * @property string|null $payment_id
 * @property string|null $deposit_id
 * @property PaymentChannel $channel
 * @property int $amount_minor
 * @property string $currency
 * @property string $state
 * @property string|null $transaction_reference
 * @property string|null $card_brand
 * @property string|null $card_last_four
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $business_date
 * @property-read Payment|null $payment
 */
class PaymentTenderDetail extends TenantModel
{
    protected function casts(): array
    {
        return [
            'channel' => PaymentChannel::class,
            'amount_minor' => 'integer',
            'received_at' => 'immutable_datetime',
            'business_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(GuestPaymentEvidence::class, 'tender_detail_id');
    }
}
