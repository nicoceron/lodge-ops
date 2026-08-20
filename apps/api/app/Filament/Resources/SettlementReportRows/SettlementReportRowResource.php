<?php

namespace App\Filament\Resources\SettlementReportRows;

use App\Filament\Resources\SettlementReportRows\Pages\ManageSettlementReportRows;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\SettlementReportRow;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SettlementReportRowResource extends TenantResource
{
    protected static ?string $model = SettlementReportRow::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Settlement report exceptions';

    protected static ?int $navigationSort = 15;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('application_state', ['mismatched', 'unmatched']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('recorded_at')->label('Recorded')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('settlementReportImport.report_type')->label('Report')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('settlementReportImport.provider_account')->label('Provider account')->copyable(),
            TextColumn::make('property.name')->label('Property')->placeholder('Tenant-wide / unknown'),
            TextColumn::make('source_id')->label('Provider source')->copyable()->placeholder('Unknown'),
            TextColumn::make('external_reference')->label('External reference')->copyable()->placeholder('Not returned'),
            TextColumn::make('currency')->badge()->placeholder('Unknown'),
            TextColumn::make('reported_amount')->label('Reported amount')->state(
                fn (SettlementReportRow $record): ?string => $record->reportedAmount(),
            )->placeholder('Unknown'),
            TextColumn::make('row_kind')->label('Row kind')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('application_state')->label('Result')->badge()->formatStateUsing(InnPresentation::label(...)),
        ])->filters([
            SelectFilter::make('application_state')->options(['mismatched' => 'Mismatched', 'unmatched' => 'Unmatched']),
        ])->defaultSort('recorded_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSettlementReportRows::route('/')];
    }
}
