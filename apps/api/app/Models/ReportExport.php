<?php

namespace App\Models;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $requested_by
 * @property string $property_id
 * @property ReportExportKind $kind
 * @property ReportExportFormat $format
 * @property ReportExportStatus $status
 * @property string $locale
 * @property array<string, mixed> $filters
 * @property string $filter_checksum
 * @property string $deduplication_key
 * @property string|null $storage_path
 * @property string|null $storage_disk
 * @property string|null $file_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $checksum
 * @property int $row_count
 * @property int $attempts
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $purged_at
 * @property string|null $last_error
 * @property-read Property $property
 */
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
