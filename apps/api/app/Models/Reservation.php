<?php

namespace App\Models;

use App\Enums\FolioStatus;
use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $actual_start_at
 * @property CarbonImmutable|null $actual_end_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $closure_reason
 * @property string $property_id
 * @property string|null $program_id
 * @property string $currency
 * @property int $adults
 * @property int $children
 * @property int $total_minor
 * @property array<string, mixed>|null $price_snapshot
 * @property array<string, mixed>|null $deposit_policy_snapshot
 * @property array<string, mixed>|null $cancellation_policy_snapshot
 * @property FolioStatus $folio_status
 * @property CarbonImmutable|null $folio_closed_at
 * @property int|null $folio_closed_by
 * @property ReservationStatus $status
 * @property-read Program|null $program
 * @property-read Property $property
 * @property-read BookingQuote|null $bookingQuote
 * @property-read Guest|null $primaryGuest
 * @property-read Collection<int, Allocation> $allocations
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, PaymentRequest> $paymentRequests
 * @property-read Collection<int, FolioLine> $folioLines
 * @property-read Collection<int, Deposit> $deposits
 * @property-read Collection<int, ReservationNote> $noteTimeline
 * @property-read Collection<int, ReservationStatusHistory> $statusHistory
 * @property-read Collection<int, ReservationChange> $changes
 * @property-read Collection<int, GeneratedDocument> $generatedDocuments
 * @property-read Collection<int, GuestPortalProfile> $guestPortalProfiles
 */
class Reservation extends TenantModel
{
    protected $attributes = [
        'folio_status' => FolioStatus::Open->value,
    ];

    protected static function booted(): void
    {
        static::saving(function (Reservation $reservation): void {
            if ($reservation->program_id !== null && ! Program::query()
                ->whereKey($reservation->program_id)
                ->where('property_id', $reservation->property_id)
                ->exists()) {
                throw new LogicException('The reservation program must belong to its property and tenant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'actual_start_at' => 'immutable_datetime',
            'actual_end_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'price_snapshot' => 'array',
            'deposit_policy_snapshot' => 'array',
            'cancellation_policy_snapshot' => 'array',
            'folio_status' => FolioStatus::class,
            'folio_closed_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<BookingQuote, $this> */
    public function bookingQuote(): BelongsTo
    {
        return $this->belongsTo(BookingQuote::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id');
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')
            ->using(ReservationGuest::class)
            ->withPivot(['id', 'tenant_id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<Allocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function tenderDetails(): HasMany
    {
        return $this->hasMany(PaymentTenderDetail::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    /** @return HasMany<Deposit, $this> */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function operationalTasks(): HasMany
    {
        return $this->hasMany(OperationalTask::class);
    }

    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function documentGenerationRequests(): HasMany
    {
        return $this->hasMany(DocumentGenerationRequest::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->orderByDesc('changed_at');
    }

    /** @return HasMany<ReservationChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ReservationChange::class)->orderByDesc('occurred_at');
    }

    public function noteTimeline(): HasMany
    {
        return $this->hasMany(ReservationNote::class)->orderByDesc('occurred_at');
    }

    public function folioClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'folio_closed_by');
    }

    public function guestPortalAccessTokens(): HasMany
    {
        return $this->hasMany(GuestPortalAccessToken::class);
    }

    /** @return HasMany<GuestPortalProfile, $this> */
    public function guestPortalProfiles(): HasMany
    {
        return $this->hasMany(GuestPortalProfile::class);
    }

    public function guestPortalAcknowledgements(): HasMany
    {
        return $this->hasMany(GuestPortalAcknowledgement::class);
    }

    public function guestPaymentEvidence(): HasMany
    {
        return $this->hasMany(GuestPaymentEvidence::class);
    }

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }
}
