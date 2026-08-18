<?php

namespace App\Models;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends TenantModel
{
    protected function casts(): array
    {
        return [
            'kind' => ReportExportKind::class,
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
