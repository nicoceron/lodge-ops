<?php

namespace App\Filament\Resources\ServiceOccurrences;

use App\Filament\Resources\ServiceOccurrences\Pages\ManageServiceOccurrences;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Program;
use App\Models\Property;
use App\Models\ServiceOccurrence;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceOccurrenceResource extends TenantResource
{
    protected static ?string $model = ServiceOccurrence::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Scheduled activities';

    protected static ?string $viewCapability = 'canManageAvailability';

    protected static string $writeCapability = 'canScheduleOperations';

    protected static bool $canDeleteRecords = false;

    public static function getEloquentQuery(): Builder
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return parent::getEloquentQuery()->when($propertyId, fn (Builder $query, string $id): Builder => $query->where('property_id', $id));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activity occurrence')->columns(2)->schema([
                Select::make('property_id')->options(static::propertyOptions(...))->searchable()->required(),
                Select::make('program_id')->options(static::programOptions(...))->searchable()->required(),
                DateTimePicker::make('starts_at')->required()->seconds(false),
                DateTimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
                TextInput::make('capacity')->integer()->minValue(1)->default(1)->required(),
                TextInput::make('meeting_point')->maxLength(255),
                Toggle::make('is_cancelled')->default(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('program.name')->label('Activity')->searchable()->weight('medium'),
                TextColumn::make('property.name')->label('Property'),
                TextColumn::make('starts_at')->label('Starts')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Ends')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('capacity')->numeric(),
                TextColumn::make('meeting_point')->placeholder('—'),
                IconColumn::make('is_cancelled')->label('Cancelled')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ServiceOccurrence $record): bool => ! $record->is_cancelled)
                    ->action(fn (ServiceOccurrence $record) => $record->update(['is_cancelled' => true])),
            ])
            ->defaultSort('starts_at');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServiceOccurrences::route('/')];
    }

    private static function propertyOptions(): array
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return Property::query()->when($propertyId, fn (Builder $query, string $id): Builder => $query->whereKey($id))
            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function programOptions(): array
    {
        $propertyId = app(TenantContext::class)->membership()?->property_id;

        return Program::query()->when($propertyId, fn (Builder $query, string $id): Builder => $query->where('property_id', $id))
            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
