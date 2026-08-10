<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Reservation extends TenantModel
{
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
            'hold_expires_at' => 'immutable_datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function operationalTasks(): HasMany
    {
        return $this->hasMany(OperationalTask::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->orderByDesc('changed_at');
    }

    public function guestPortalAccessTokens(): HasMany
    {
        return $this->hasMany(GuestPortalAccessToken::class);
    }

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
