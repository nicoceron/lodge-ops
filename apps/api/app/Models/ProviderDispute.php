<?php

namespace App\Models;

use App\Enums\ProviderDisputeImpactState;
use App\Enums\ProviderDisputeState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $payment_id
 * @property string $provider_payment_id
 * @property ProviderDisputeState $state
 * @property ProviderDisputeImpactState $impact_state
 * @property int $amount_minor
 * @property string $currency
 */
class ProviderDispute extends TenantModel
{
    protected function casts(): array
    {
        return [
            'state' => ProviderDisputeState::class,
            'impact_state' => ProviderDisputeImpactState::class,
            'amount_minor' => 'integer',
            'coverage_applied' => 'boolean',
            'documentation_required' => 'boolean',
            'documentation_deadline' => 'immutable_datetime',
            'provider_created_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ProviderDisputeRevision::class)->orderBy('revision');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
