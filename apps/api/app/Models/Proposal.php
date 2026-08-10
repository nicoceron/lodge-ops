<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use App\Exceptions\CommercialWorkflowException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Proposal extends TenantModel
{
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'adults' => 'integer',
            'children' => 'integer',
            'version' => 'integer',
            'total_minor' => 'integer',
            'tax_minor' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            if ($proposal->getRawOriginal('status') === ProposalStatus::Draft->value) {
                return;
            }

            if ($proposal->isDirty([
                'reference',
                'version',
                'property_id',
                'primary_guest_id',
                'starts_at',
                'ends_at',
                'adults',
                'children',
                'currency',
                'total_minor',
                'tax_minor',
                'snapshot',
                'expires_at',
                'created_by',
            ])) {
                throw new CommercialWorkflowException('Sent proposal snapshots are immutable. Create a revision instead.');
            }
        });
    }
}
