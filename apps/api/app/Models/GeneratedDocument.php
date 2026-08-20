<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $document_generation_request_id
 * @property string|null $document_template_id
 * @property string|null $reservation_id
 * @property string|null $guest_id
 * @property string|null $payment_id
 * @property string|null $reservation_change_id
 * @property string|null $replaces_document_id
 * @property string $kind
 * @property string $status
 * @property string $storage_path
 * @property string $storage_disk
 * @property string $file_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property string $source_checksum
 * @property string $renderer
 * @property string $renderer_version
 * @property int $template_version
 * @property string $locale
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $purged_at
 * @property-read Reservation|null $reservation
 * @property-read Guest|null $guest
 * @property-read Payment|null $payment
 * @property-read ReservationChange|null $reservationChange
 * @property-read DocumentGenerationRequest|null $generationRequest
 */
class GeneratedDocument extends TenantModel
{
    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'metadata' => 'array',
            'size_bytes' => 'integer',
            'template_version' => 'integer',
            'generated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DocumentGenerationRequest, $this> */
    public function generationRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentGenerationRequest::class, 'document_generation_request_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<ReservationChange, $this> */
    public function reservationChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_document_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'replaces_document_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            $mutableLifecycleFields = ['purged_at', 'updated_at'];
            if (array_diff(array_keys($document->getDirty()), $mutableLifecycleFields) !== []) {
                throw new LogicException('Generated documents are immutable. Generate a replacement instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Generated documents cannot be deleted from the audit record.'));
    }
}
