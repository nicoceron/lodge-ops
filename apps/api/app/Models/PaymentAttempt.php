<?php

namespace App\Models;

use App\Enums\PaymentAttemptState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $property_id
 * @property string $reservation_id
 * @property string $payment_request_id
 * @property string|null $deposit_id
 * @property string $integration_connection_id
 * @property string $provider
 * @property string $environment
 * @property string $provider_account
 * @property string $external_reference
 * @property string $idempotency_key
 * @property PaymentAttemptState $state
 * @property int $source_amount_minor
 * @property string $source_currency
 * @property int $charge_amount_minor
 * @property string $charge_currency
 * @property string|null $exchange_rate
 * @property array<string, mixed>|null $conversion_snapshot
 * @property string|null $provider_preference_id
 * @property string|null $provider_payment_id
 * @property string|null $hosted_checkout_url
 * @property CarbonImmutable|null $checkout_expires_at
 * @property CarbonImmutable|null $order_expires_at
 * @property CarbonImmutable|null $provider_order_created_at
 * @property CarbonImmutable|null $provider_order_updated_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $at_terminal_at
 * @property CarbonImmutable|null $action_required_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $canceled_at
 * @property string|null $provider_status
 * @property string|null $provider_status_detail
 * @property int $attempt_count
 * @property int $error_count
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_processed_at
 * @property-read Reservation $reservation
 * @property-read PaymentRequest $paymentRequest
 * @property-read IntegrationConnection $integrationConnection
 */
class PaymentAttempt extends TenantModel
{
    protected function casts(): array
    {
        return [
            'state' => PaymentAttemptState::class,
            'source_amount_minor' => 'integer',
            'charge_amount_minor' => 'integer',
            'exchange_rate' => 'string',
            'conversion_snapshot' => 'array',
            'qr_data_ciphertext' => 'encrypted',
            'checkout_expires_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'last_processed_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'at_terminal_at' => 'immutable_datetime',
            'action_required_at' => 'immutable_datetime',
            'order_expires_at' => 'immutable_datetime',
            'provider_order_created_at' => 'immutable_datetime',
            'provider_order_updated_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'attempt_count' => 'integer',
            'error_count' => 'integer',
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

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function integrationConnection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class);
    }

    public function paymentTerminal(): BelongsTo
    {
        return $this->belongsTo(PaymentTerminal::class);
    }

    public function providerPosLocation(): BelongsTo
    {
        return $this->belongsTo(ProviderPosLocation::class);
    }

    public function providerDisputes(): HasMany
    {
        return $this->hasMany(ProviderDispute::class);
    }

    public function hasDisplayableQr(): bool
    {
        return $this->channel === 'qr' && $this->state->reusable() && $this->qr_data_ciphertext !== null
            && $this->order_expires_at !== null && $this->order_expires_at->isFuture();
    }
}
