<?php

namespace App\Filament\Resources\SettlementEntries;

use App\Enums\SettlementReconciliationState;
use App\Filament\Resources\SettlementEntries\Pages\ManageSettlementEntries;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\SettlementEntry;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SettlementEntryResource extends TenantResource
{
    protected static ?string $model = SettlementEntry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 14;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canViewFinance';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Recorded')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
            TextColumn::make('source_type')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('source_id')->label('Provider source')->copyable(),
            TextColumn::make('gross_minor')->label('Gross')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('fee_minor')->label('Fees')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('net_minor')->label('Net')->money(fn (SettlementEntry $record): string => $record->currency, divideBy: 100),
            TextColumn::make('reconciliation_state')->label('Reconciliation')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
        ])->filters([SelectFilter::make('reconciliation_state')->options(InnPresentation::enumOptions(SettlementReconciliationState::cases()))])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageSettlementEntries::route('/')];
    }
}
