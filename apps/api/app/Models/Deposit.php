<?php

namespace App\Models;

use App\Enums\DepositStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DepositStatus $status
 * @property CarbonImmutable|null $due_at
 */
class Deposit extends TenantModel
{
    protected function casts(): array
    {
        return [
            'status' => DepositStatus::class,
            'amount_minor' => 'integer',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'waived_at' => 'immutable_datetime',
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

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }
}
