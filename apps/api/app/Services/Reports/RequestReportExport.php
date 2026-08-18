<?php

namespace App\Services\Reports;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Enums\ReportExportStatus;
use App\Jobs\GenerateReportExport;
use App\Models\Property;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Services\Documents\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class RequestReportExport
{
    public function __construct(private readonly ReportDefinitionRegistry $registry, private readonly CanonicalJson $canonical, private readonly OutboxRecorder $outbox) {}

    /** @param array<string, mixed> $filters */
    public function handle(User $actor, Property $property, ReportExportKind $kind, ReportExportFormat $format, array $filters, string $locale, string $idempotencyKey): ReportExport
    {
        $actor->can('createFor', [ReportExport::class, $kind, $property]) || abort(403);
        $definition = $this->registry->get($kind);
        $normalized = $definition->normalizeFilters($filters, $property->timezone ?: 'UTC');
        $checksum = $this->canonical->checksum($normalized);
        $dedup = hash('sha256', implode('|', [$property->id, $kind->value, $format->value, $locale, $checksum, $idempotencyKey]));
        $tenantId = app(TenantContext::class)->tenant()->id;
        $export = DB::transaction(function () use ($actor, $property, $kind, $format, $locale, $normalized, $checksum, $dedup, $tenantId): ReportExport {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            if ($existing = ReportExport::query()->where('deduplication_key', $dedup)->first()) {
                return $existing;
            }
            $created = ReportExport::query()->create(['requested_by' => $actor->id, 'property_id' => $property->id, 'kind' => $kind, 'format' => $format, 'locale' => $locale, 'filters' => $normalized, 'filter_checksum' => $checksum, 'deduplication_key' => $dedup, 'status' => ReportExportStatus::Pending]);
            $this->outbox->record('report_export', $created->id, 'report.export.requested', ['export_id' => $created->id, 'kind' => $kind->value, 'format' => $format->value]);

            return $created;
        }, 3);
        if ($export->wasRecentlyCreated) {
            GenerateReportExport::dispatch($export->id);
        }

        return $export;
    }
}
