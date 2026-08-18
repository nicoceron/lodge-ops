<?php

namespace App\Services\Reports;

use App\Enums\ReportExportStatus;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use DomainException;

final class RetryReportExport
{
    public function handle(User $actor, ReportExport $export): ReportExport
    {
        $actor->can('retry', $export) || abort(403);
        if ($export->status !== ReportExportStatus::Failed) {
            throw new DomainException('Only failed report exports can be retried.');
        }
        $export->forceFill(['status' => ReportExportStatus::Pending, 'failed_at' => null, 'last_error' => null])->save();
        GenerateReportExport::dispatch($export->id);

        return $export->refresh();
    }
}
