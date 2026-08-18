<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReportExportResource;
use App\Models\Property;
use App\Models\ReportExport;
use App\Services\Reports\RequestReportExport;
use App\Services\Reports\RetryReportExport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportExportController extends Controller
{
    public function store(Request $request, RequestReportExport $command): ReportExportResource
    {
        $data = $request->validate(['property_id' => ['required', 'uuid'], 'kind' => ['required', Rule::enum(ReportExportKind::class)], 'format' => ['required', Rule::enum(ReportExportFormat::class)], 'locale' => ['sometimes', 'string', 'max:12'], 'filters' => ['sometimes', 'array']]);
        $property = Property::query()->findOrFail($data['property_id']);
        $export = $command->handle($request->user(), $property, ReportExportKind::from($data['kind']), ReportExportFormat::from($data['format']), $data['filters'] ?? [], $data['locale'] ?? 'en', $request->header('Idempotency-Key', hash('sha256', json_encode($data))));

        return new ReportExportResource($export);
    }

    public function show(ReportExport $reportExport): ReportExportResource
    {
        $this->authorize('view', $reportExport);

        return new ReportExportResource($reportExport);
    }

    public function retry(Request $request, ReportExport $reportExport, RetryReportExport $command): ReportExportResource
    {
        return new ReportExportResource($command->handle($request->user(), $reportExport));
    }
}
