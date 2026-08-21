<?php

namespace App\Models;

use App\Enums\ProviderRefundState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property string $reservation_change_id
 * @property string $provider
 * @property string $environment
 * @property string $provider_account
 * @property int $source_amount_minor
 * @property string $source_currency
 * @property int $charge_amount_minor
 * @property string $charge_currency
 * @property string $idempotency_key
 * @property string $provider_payment_id
 * @property string|null $provider_refund_id
 * @property ProviderRefundState $state
 * @property int $attempt_count
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_attempted_at
 * @property-read Payment $payment
 * @property-read ReservationChange $reservationChange
 */
class ProviderRefund extends TenantModel
{
    protected function casts(): array
    {
        return [
            'state' => ProviderRefundState::class,
            'source_amount_minor' => 'integer',
            'charge_amount_minor' => 'integer',
            'attempt_count' => 'integer',
            'provider_action_required' => 'boolean',
            'last_attempted_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservationChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }
}
