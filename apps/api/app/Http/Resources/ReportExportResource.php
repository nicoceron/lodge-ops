<?php

namespace App\Http\Resources;

use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportExport */
class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'kind' => $this->kind->value, 'format' => $this->format->value, 'status' => $this->status->value, 'property_id' => $this->property_id, 'locale' => $this->locale, 'filters' => $this->filters, 'filter_checksum' => $this->filter_checksum, 'file_name' => $this->file_name, 'mime_type' => $this->mime_type, 'size_bytes' => $this->size_bytes, 'checksum' => $this->checksum, 'row_count' => $this->row_count, 'attempts' => $this->attempts, 'started_at' => $this->started_at?->toIso8601String(), 'completed_at' => $this->completed_at?->toIso8601String(), 'failed_at' => $this->failed_at?->toIso8601String(), 'last_error' => $this->last_error, 'expires_at' => $this->expires_at?->toIso8601String(), 'purged_at' => $this->purged_at?->toIso8601String()];
    }
}
