<?php

namespace App\Http\Resources;

use App\Models\DocumentGenerationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentGenerationRequest */
class DocumentGenerationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind->value, 'status' => $this->status->value,
            'locale' => $this->locale, 'reservation_id' => $this->reservation_id,
            'payment_id' => $this->payment_id, 'reservation_change_id' => $this->reservation_change_id,
            'source_checksum' => $this->source_checksum, 'attempts' => $this->attempts,
            'started_at' => $this->started_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(), 'last_error' => $this->last_error,
            'generated_document_id' => $this->generatedDocument?->id,
        ];
    }
}
