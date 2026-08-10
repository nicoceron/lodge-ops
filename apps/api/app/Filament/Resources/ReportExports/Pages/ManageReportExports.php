<?php

namespace App\Filament\Resources\ReportExports\Pages;

use App\Filament\Resources\ReportExports\ReportExportResource;
use Filament\Resources\Pages\ManageRecords;

class ManageReportExports extends ManageRecords
{
    protected static string $resource = ReportExportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
