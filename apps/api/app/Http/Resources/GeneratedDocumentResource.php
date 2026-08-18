<?php

namespace App\Http\Resources;

use App\Models\GeneratedDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GeneratedDocument */
class GeneratedDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'status' => $this->status,
            'reservation_id' => $this->reservation_id, 'guest_id' => $this->guest_id,
            'payment_id' => $this->payment_id, 'reservation_change_id' => $this->reservation_change_id,
            'file_name' => $this->file_name, 'mime_type' => $this->mime_type, 'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum, 'source_checksum' => $this->source_checksum,
            'template_version' => $this->template_version, 'locale' => $this->locale,
            'generated_at' => $this->generated_at->toIso8601String(), 'expires_at' => $this->expires_at?->toIso8601String(),
            'purged_at' => $this->purged_at?->toIso8601String(), 'replaces_document_id' => $this->replaces_document_id,
        ];
    }
}
