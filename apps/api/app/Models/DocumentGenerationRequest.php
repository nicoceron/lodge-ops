<?php

namespace App\Models;

use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int|null $requested_by
 * @property string|null $document_template_id
 * @property string|null $reservation_id
 * @property string|null $guest_id
 * @property string|null $payment_id
 * @property string|null $reservation_change_id
 * @property DocumentKind $kind
 * @property DocumentGenerationStatus $status
 * @property string $locale
 * @property array<string, mixed> $source_snapshot
 * @property string $source_checksum
 * @property string $deduplication_key
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error
 * @property-read GeneratedDocument|null $generatedDocument
 */
class DocumentGenerationRequest extends TenantModel
{
    protected $attributes = [
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentGenerationStatus::class,
            'source_snapshot' => 'array',
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reservationChange(): BelongsTo
    {
        return $this->belongsTo(ReservationChange::class);
    }

    /** @return HasOne<GeneratedDocument, $this> */
    public function generatedDocument(): HasOne
    {
        return $this->hasOne(GeneratedDocument::class);
    }
}
