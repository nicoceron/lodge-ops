<?php

namespace App\Filament\Resources\ReportExports\Pages;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportKind;
use App\Filament\Resources\ReportExports\ReportExportResource;
use App\Filament\Support\InnPresentation;
use App\Models\Property;
use App\Models\User;
use App\Services\Reports\RequestReportExport;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageReportExports extends ManageRecords
{
    protected static string $resource = ReportExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request_export')->label('Request report export')->icon('heroicon-o-arrow-down-tray')
                ->schema([
                    Select::make('property_id')->options(fn (): array => Property::query()->pluck('name', 'id')->all())->required()->searchable(),
                    Select::make('kind')->options(function (): array {
                        $role = app(TenantContext::class)->membership()->role;
                        $finance = [ReportExportKind::Revenue, ReportExportKind::PaymentsDepositsRefunds, ReportExportKind::CostsMarginCommissions];
                        $cases = array_filter(ReportExportKind::cases(), fn (ReportExportKind $kind): bool => in_array($kind, $finance, true) ? $role->canViewFinance() : $role->canManageOperations());

                        return InnPresentation::enumOptions($cases);
                    })->required(),
                    Select::make('format')->options(InnPresentation::enumOptions(ReportExportFormat::cases()))->default(ReportExportFormat::Csv->value)->required(),
                    DatePicker::make('from')->required()->default(now()->startOfMonth()),
                    DatePicker::make('to')->required()->default(now()),
                ])
                ->action(function (array $data): void {
                    app(RequestReportExport::class)->handle(User::query()->findOrFail(auth()->id()), Property::query()->findOrFail($data['property_id']), ReportExportKind::from($data['kind']), ReportExportFormat::from($data['format']), ['from' => $data['from'], 'to' => $data['to']], app()->getLocale(), (string) str()->uuid());
                    Notification::make()->success()->title('Report export queued')->send();
                }),
        ];
    }
}
