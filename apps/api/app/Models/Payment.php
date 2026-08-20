<?php

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\PaymentEntryMode;
use App\Enums\PaymentOrigin;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $reservation_id
 * @property PaymentStatus $status
 * @property string $method
 * @property PaymentOrigin $origin
 * @property PaymentChannel $channel
 * @property PaymentEntryMode $entry_mode
 * @property string|null $environment
 * @property string|null $provider_account
 * @property CarbonImmutable|null $processed_at
 * @property string $currency
 * @property int $amount_minor
 * @property-read Reservation $reservation
 * @property-read PaymentTenderDetail|null $tenderDetail
 */
class Payment extends TenantModel
{
    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            if ($payment->getAttribute('channel') !== null && $payment->getAttribute('entry_mode') !== null) {
                return;
            }
            $origin = $payment->getAttribute('origin');
            if (($origin instanceof PaymentOrigin ? $origin : PaymentOrigin::tryFrom((string) $origin)) === PaymentOrigin::Provider) {
                $payment->channel = PaymentChannel::OnlineCheckout;
                $payment->entry_mode = PaymentEntryMode::ProviderReported;

                return;
            }
            $payment->channel = match ($payment->method) {
                'bank_transfer' => PaymentChannel::BankTransfer,
                'cash' => PaymentChannel::Cash,
                'card' => PaymentChannel::ExternalTerminal,
                default => PaymentChannel::ManualOther,
            };
            $payment->entry_mode = PaymentEntryMode::StaffRecorded;
        });
        static::saving(function (Payment $payment): void {
            if ($payment->getAttribute('origin') === null) {
                $payment->origin = PaymentOrigin::Manual;
            }
            $origin = self::value($payment->origin);
            if ($payment->getAttribute('channel') === null || $payment->getAttribute('entry_mode') === null) {
                if ($origin === 'provider') {
                    $payment->channel = PaymentChannel::OnlineCheckout;
                    $payment->entry_mode = PaymentEntryMode::ProviderReported;
                } else {
                    $payment->channel = match ($payment->method) {
                        'bank_transfer' => PaymentChannel::BankTransfer,
                        'cash' => PaymentChannel::Cash,
                        'card' => PaymentChannel::ExternalTerminal,
                        default => PaymentChannel::ManualOther,
                    };
                    $payment->entry_mode = PaymentEntryMode::StaffRecorded;
                }
            }
            $channel = self::value($payment->channel);
            $entryMode = self::value($payment->entry_mode);
            $providerIdentity = [$payment->provider, $payment->provider_reference, $payment->provider_account, $payment->environment];
            $manualMap = [
                'bank_transfer' => 'bank_transfer',
                'cash' => 'cash',
                'card' => 'external_terminal',
                'other' => 'manual_other',
                'manual' => 'manual_other',
            ];
            $validManual = $origin === 'manual' && $entryMode === 'staff_recorded'
                && ($manualMap[$payment->method] ?? null) === $channel
                && collect($providerIdentity)->every(fn ($value): bool => $value === null);
            $validProvider = $origin === 'provider' && $entryMode === 'provider_reported'
                && collect($providerIdentity)->every(fn ($value): bool => is_string($value) && trim($value) !== '')
                && in_array($payment->method, ['card', 'provider', 'mercado_pago_checkout_pro'], true)
                && in_array($channel, ['online_checkout', 'integrated_terminal', 'qr'], true);
            if (! $validManual && ! $validProvider) {
                throw ValidationException::withMessages(['payment' => 'Payment origin, channel, entry mode, method, and provider identity are contradictory.']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'origin' => PaymentOrigin::class,
            'channel' => PaymentChannel::class,
            'entry_mode' => PaymentEntryMode::class,
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function documentGenerationRequests(): HasMany
    {
        return $this->hasMany(DocumentGenerationRequest::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function providerRefunds(): HasMany
    {
        return $this->hasMany(ProviderRefund::class);
    }

    public function providerDisputes(): HasMany
    {
        return $this->hasMany(ProviderDispute::class);
    }

    /** @return HasOne<PaymentTenderDetail, $this> */
    public function tenderDetail(): HasOne
    {
        return $this->hasOne(PaymentTenderDetail::class);
    }

    /** @return HasMany<FolioLine, $this> */
    public function folioLines(): HasMany
    {
        return $this->hasMany(FolioLine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function getReceiptSafeReferenceAttribute(): ?string
    {
        $detail = $this->relationLoaded('tenderDetail') ? $this->getRelation('tenderDetail') : $this->tenderDetail()->first();

        return ($detail instanceof PaymentTenderDetail ? $detail->transaction_reference : null)
            ?? $this->provider_reference
            ?? data_get($this->metadata, 'manual_transaction_reference');
    }

    private static function value(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }
}
