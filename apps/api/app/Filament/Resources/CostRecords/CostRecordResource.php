<?php

namespace App\Filament\Resources\CostRecords;

use App\Filament\Resources\CostRecords\Pages\ManageCostRecords;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\CostRecord;
use App\Models\Program;
use App\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CostRecordResource extends TenantResource
{
    protected static ?string $model = CostRecord::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'description';

    protected static ?string $viewCapability = 'canViewFinance';

    protected static string $writeCapability = 'canManageMoney';

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        if ($propertyId === null) {
            return $query;
        }

        return $query->where(fn (Builder $scope) => $scope
            ->whereHas('reservation', fn (Builder $reservation) => $reservation->where('property_id', $propertyId))
            ->orWhereHas('program', fn (Builder $program) => $program->where('property_id', $propertyId)));
    }

    public static function canView(Model $record): bool
    {
        if (! parent::canView($record) || ! $record instanceof CostRecord) {
            return false;
        }

        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return $propertyId === null
            || $record->reservation?->property_id === $propertyId
            || $record->program?->property_id === $propertyId;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cost record')->columns(2)->schema([
                Select::make('reservation_id')->label('Reservation')->options(fn (): array => Reservation::query()
                    ->when(app(TenantContext::class)->membership()?->property_id, fn (Builder $query, string $propertyId) => $query->where('property_id', $propertyId))
                    ->orderByDesc('starts_at')->limit(100)->pluck('confirmation_number', 'id')->all())->searchable(),
                Select::make('program_id')->label('Program')->options(fn (): array => Program::query()
                    ->when(app(TenantContext::class)->membership()?->property_id, fn (Builder $query, string $propertyId) => $query->where('property_id', $propertyId))
                    ->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Select::make('staff_user_id')->label('Staff member')->relationship('staffUser', 'name')->searchable()->preload(),
                Select::make('kind')->options(['estimated' => 'Estimated', 'actual' => 'Actual'])->required()->default('actual'),
                TextInput::make('category')->required()->maxLength(50),
                TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
                TextInput::make('currency')->required()->length(3)->default(fn (): string => app(TenantContext::class)->tenant()->currency)->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                TextInput::make('amount_minor')->label('Amount · minor units')->numeric()->minValue(0)->required(),
                DateTimePicker::make('occurred_at')->label('Occurred')->required()->default(now())->seconds(false),
                KeyValue::make('metadata')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cost record')->columns(2)->schema([
                TextEntry::make('description')->columnSpanFull()->weight('bold'),
                TextEntry::make('amount_minor')->label('Amount')->money(fn (CostRecord $record): string => $record->currency, divideBy: 100),
                TextEntry::make('kind')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('category'),
                TextEntry::make('occurred_at')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->placeholder('—'),
                TextEntry::make('program.name')->label('Program')->placeholder('—'),
                TextEntry::make('staffUser.name')->label('Staff member')->placeholder('—'),
                KeyValueEntry::make('metadata')->columnSpanFull()->placeholder('No metadata'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Date')->dateTime('M j, Y', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('description')->searchable()->limit(45)->weight('medium'),
                TextColumn::make('category')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->searchable(),
                TextColumn::make('kind')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('amount_minor')->label('Amount')->money(fn (CostRecord $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->placeholder('—')->searchable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(['estimated' => 'Estimated', 'actual' => 'Actual']),
                SelectFilter::make('reservation_id')->label('Reservation')->options(LodgeOpsPresentation::reservationOptions(...))->searchable(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading('No costs recorded');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCostRecords::route('/')];
    }
}
