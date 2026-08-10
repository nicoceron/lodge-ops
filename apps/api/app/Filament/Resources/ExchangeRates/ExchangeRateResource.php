<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Filament\Resources\ExchangeRates\Pages\ManageExchangeRates;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\ExchangeRate;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRateResource extends TenantResource
{
    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance & Reporting';

    protected static ?int $navigationSort = 30;

    protected static ?string $viewCapability = 'canManageMoney';

    protected static string $writeCapability = 'canManageMoney';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable rate snapshot')->description('Rates are appended as dated snapshots and never edited in place.')->columns(2)->schema([
                TextInput::make('base_currency')->label('Base currency')->required()->length(3)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('quote_currency')->label('Quote currency')->required()->length(3)->different('base_currency')->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('rate')->required()->numeric()->minValue(0.0000000001),
                TextInput::make('source')->required()->maxLength(80),
                DateTimePicker::make('effective_at')->required()->default(now())->seconds(false)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Rate snapshot')->columns(2)->schema([
                TextEntry::make('base_currency')->label('Base'),
                TextEntry::make('quote_currency')->label('Quote'),
                TextEntry::make('rate')->numeric(decimalPlaces: 10)->weight('bold'),
                TextEntry::make('source'),
                TextEntry::make('effective_at')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_at')->label('Effective')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('base_currency')->label('Base')->badge(),
                TextColumn::make('quote_currency')->label('Quote')->badge(),
                TextColumn::make('rate')->numeric(decimalPlaces: 10)->copyable(),
                TextColumn::make('source')->searchable(),
            ])
            ->filters([
                SelectFilter::make('base_currency')->options(fn (): array => ExchangeRate::query()->distinct()->pluck('base_currency', 'base_currency')->all()),
                SelectFilter::make('quote_currency')->options(fn (): array => ExchangeRate::query()->distinct()->pluck('quote_currency', 'quote_currency')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('effective_at', 'desc')
            ->emptyStateHeading('No exchange-rate snapshots');
    }

    public static function getPages(): array
    {
        return ['index' => ManageExchangeRates::route('/')];
    }
}
