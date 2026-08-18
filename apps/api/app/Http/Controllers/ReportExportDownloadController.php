<?php

namespace App\Http\Controllers;

use App\Enums\ReportExportStatus;
use App\Models\Audit;
use App\Models\ReportExport;
use App\Services\Reports\ReportArtifactStore;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportExportDownloadController extends Controller
{
    public function __invoke(Request $request, ReportArtifactStore $artifacts): Response
    {
        $reportExport = ReportExport::query()->findOrFail((string) $request->route('reportExport'));
        $this->authorize('download', $reportExport);
        abort_if($reportExport->status !== ReportExportStatus::Completed, 409, 'The report export is not complete.');
        abort_if($reportExport->expires_at?->isPast() || $reportExport->purged_at !== null, 410, 'This report export is no longer available.');
        try {
            $bytes = $artifacts->verifiedBytes($reportExport->storage_disk, $reportExport->storage_path, $reportExport->checksum);
        } catch (DomainException) {
            abort(503, 'The report export is temporarily unavailable.');
        }
        Audit::query()->create(['actor_id' => auth()->id(), 'event' => 'report_downloaded', 'auditable_type' => $reportExport->getMorphClass(), 'auditable_id' => $reportExport->id, 'new_values' => ['channel' => 'staff'], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return response($bytes, 200, ['Content-Type' => $reportExport->mime_type, 'Content-Disposition' => 'attachment; filename="'.$reportExport->file_name.'"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
