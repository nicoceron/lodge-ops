<?php

namespace App\Models;

use App\Enums\DocumentGenerationStatus;
use App\Enums\DocumentKind;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function generatedDocument(): HasOne
    {
        return $this->hasOne(GeneratedDocument::class);
    }
}
