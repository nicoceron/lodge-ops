<?php

namespace App\Jobs;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Models\Membership;
use App\Models\ReportExport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\OutboxRecorder;
use App\Services\Reports\CsvReportWriter;
use App\Services\Reports\ReportArtifactStore;
use App\Services\Reports\ReportDefinitionRegistry;
use App\Services\Reports\XlsxReportWriter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GenerateReportExport implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $exportId)
    {
        $this->tries = (int) config('documents.jobs.exports.tries');
        $this->timeout = (int) config('documents.jobs.exports.timeout');
        $this->onQueue((string) config('documents.jobs.exports.queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return config('documents.jobs.exports.backoff');
    }

    public function retryUntil(): CarbonImmutable
    {
        return now()->toImmutable()->addMinutes((int) config('documents.jobs.exports.retry_for_minutes'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('report:'.$this->exportId))->expireAfter((int) config('documents.jobs.exports.overlap_expire_after'))];
    }

    public function handle(ReportDefinitionRegistry $registry, CsvReportWriter $csv, XlsxReportWriter $xlsx, ReportArtifactStore $artifacts, OutboxRecorder $outbox, TenantContext $context): void
    {
        $unscoped = ReportExport::withoutGlobalScopes()->findOrFail($this->exportId);
        $tenant = Tenant::query()->findOrFail($unscoped->tenant_id);
        $previous = $context->check() ? [$context->tenant(), $context->membership()] : null;
        $context->clear();
        $context->set($tenant);
        $stored = null;
        try {
            $membership = Membership::query()->where('user_id', $unscoped->requested_by)->where('is_active', true)->firstOrFail();
            $context->set($tenant, $membership);
            $export = DB::transaction(function (): ReportExport {
                $locked = ReportExport::query()->whereKey($this->exportId)->lockForUpdate()->firstOrFail();
                if ($locked->status === ReportExportStatus::Completed) {
                    return $locked;
                }
                $locked->forceFill(['status' => ReportExportStatus::Processing, 'attempts' => $locked->attempts + 1, 'started_at' => now(), 'failed_at' => null, 'last_error' => null])->save();

                return $locked;
            }, 3);
            if ($export->status === ReportExportStatus::Completed) {
                return;
            }
            $actor = User::query()->findOrFail($export->requested_by);
            $actor->can('view', $export) || abort(403);
            $export->loadMissing('property');
            $definition = $registry->get($export->kind);
            $rows = $definition->rows($export->property_id, $export->filters, $export->property->timezone ?: 'UTC');
            $result = ($export->format === ReportExportFormat::Csv ? $csv : $xlsx)->write($definition->columns($export->locale), $rows);
            $stored = $artifacts->put($export->tenant_id, $export->id, $export->format, $result['bytes'], $export->kind->value);
            DB::transaction(function () use ($export, $result, $stored, $outbox): void {
                $locked = ReportExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['status' => ReportExportStatus::Completed, 'storage_path' => $stored['path'], 'storage_disk' => $stored['disk'], 'file_name' => $stored['file_name'], 'mime_type' => $stored['mime_type'], 'size_bytes' => $stored['size_bytes'], 'checksum' => $stored['checksum'], 'row_count' => $result['row_count'], 'completed_at' => now(), 'expires_at' => now()->addDays((int) config('documents.exports.ttl_days')), 'last_error' => null])->save();
                $outbox->record('report_export', $locked->id, 'report.export.completed', ['export_id' => $locked->id, 'row_count' => $result['row_count']]);
            }, 3);
        } catch (Throwable $exception) {
            if (is_array($stored)) {
                $artifacts->delete($stored['disk'], $stored['path']);
            }
            ReportExport::query()->whereKey($this->exportId)->update(['status' => ReportExportStatus::Failed->value, 'failed_at' => now(), 'last_error' => Str::limit(class_basename($exception).': '.$exception->getMessage(), 1000)]);
            throw $exception;
        } finally {
            $context->clear();
            if ($previous !== null) {
                $context->set($previous[0], $previous[1]);
            }
        }
    }
}
