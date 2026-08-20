<?php

namespace App\Models;

use App\Enums\PaymentEvidenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $submitted_at
 * @property PaymentEvidenceStatus $status
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property string $reservation_id
 * @property-read Guest|null $guest
 */
class GuestPaymentEvidence extends TenantModel
{
    protected $table = 'guest_payment_evidence';

    protected $hidden = ['storage_path', 'storage_key', 'disk'];

    protected function casts(): array
    {
        return [
            'status' => PaymentEvidenceStatus::class,
            'size_bytes' => 'integer',
            'amount_minor' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'scanned_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function refundChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class, 'refund_change_id');
    }

    public function tenderDetail(): BelongsTo
    {
        return $this->belongsTo(PaymentTenderDetail::class, 'tender_detail_id');
    }
}
