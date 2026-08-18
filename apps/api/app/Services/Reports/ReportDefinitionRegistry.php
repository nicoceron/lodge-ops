<?php

namespace App\Services\Reports;

use App\Contracts\Reports\ReportDefinition;
use App\Enums\ReportExportKind;

final class ReportDefinitionRegistry
{
    public function get(ReportExportKind $kind): ReportDefinition
    {
        return new DatabaseReportDefinition($kind);
    }
}
