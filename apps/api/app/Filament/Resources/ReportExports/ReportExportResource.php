<?php

namespace App\Filament\Resources\ReportExports;

use App\Filament\Resources\ReportExports\Pages\ManageReportExports;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\ReportExport;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportExportResource extends TenantResource
{
    protected static ?string $model = ReportExport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance & Reporting';

    protected static ?int $navigationSort = 50;

    protected static ?string $viewCapability = 'canManageMoney';

    protected static string $writeCapability = 'canManageMoney';

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Export job')->columns(2)->schema([
                TextEntry::make('kind')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('requestedBy.name')->label('Requested by'),
                TextEntry::make('row_count')->label('Rows')->numeric(),
                TextEntry::make('created_at')->label('Requested')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('completed_at')->label('Completed')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Pending'),
                TextEntry::make('storage_path')->label('Private storage path')->copyable()->placeholder('Not ready')->columnSpanFull(),
                KeyValueEntry::make('filters')->columnSpanFull()->placeholder('No filters'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('requestedBy.name')->label('Requested by')->searchable(),
                TextColumn::make('row_count')->label('Rows')->numeric()->sortable(),
                TextColumn::make('completed_at')->label('Completed')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(fn (): array => ReportExport::query()->distinct()->pluck('status', 'status')->mapWithKeys(fn (string $value): array => [$value => LodgeOpsPresentation::label($value)])->all())])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No report exports requested');
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportExports::route('/')];
    }
}
