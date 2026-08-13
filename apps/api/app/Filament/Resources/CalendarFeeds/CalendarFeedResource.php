<?php

namespace App\Filament\Resources\CalendarFeeds;

use App\Filament\Resources\CalendarFeeds\Pages\ManageCalendarFeeds;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\CalendarFeed;
use App\Models\Resource;
use App\Services\CalendarFeedService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CalendarFeedResource extends TenantResource
{
    protected static ?string $model = CalendarFeed::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 45;

    protected static ?string $modelLabel = 'channel calendar feed';

    protected static ?string $pluralModelLabel = 'channel calendar feeds';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Channel availability export')
                ->description('Publish confirmed allocations, active holds, and blocks as a private iCalendar feed for an external channel.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->placeholder('Airbnb · River Cabin')->required()->maxLength(160),
                    Select::make('property_id')->options(LodgeOpsPresentation::propertyOptions(...))->live()->required(),
                    Select::make('resource_id')
                        ->label('Exported resource')
                        ->options(fn (Get $get): array => Resource::query()
                            ->when($get('property_id'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')->default(true)->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('resource.name')->label('Resource')->description(fn (CalendarFeed $record): string => $record->property->name),
                TextColumn::make('url')
                    ->label('Private feed URL')
                    ->state(fn (CalendarFeed $record): string => app(CalendarFeedService::class)->url($record))
                    ->copyable()
                    ->limit(42),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('last_accessed_at')->label('Last pulled')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Never'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No channel feeds')
            ->emptyStateDescription('Create a private iCalendar URL for each resource that an external booking channel should block.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCalendarFeeds::route('/')];
    }
}
