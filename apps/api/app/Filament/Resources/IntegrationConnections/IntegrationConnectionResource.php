<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Filament\Resources\IntegrationConnections\Pages\ManageIntegrationConnections;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationConnection;
use App\Services\IntegrationConnectionService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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

class IntegrationConnectionResource extends TenantResource
{
    protected static ?string $model = IntegrationConnection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Integration')->description('Credentials stay in an external secret manager; only a reference may be saved here.')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160)->disabledOn('edit'),
                Select::make('type')->options(IntegrationConnection::TYPES)->required()->disabledOn('edit'),
                KeyValue::make('configuration')->label('Non-secret configuration')->columnSpanFull(),
                TextInput::make('secret_reference')->label('Secret manager reference')->password()->revealable()->maxLength(500)->helperText('Use an approved vault, cloud secret-manager, or environment reference.')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection health')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('type')->badge()->formatStateUsing(fn (string $state): string => IntegrationConnection::TYPES[$state] ?? InnPresentation::label($state)),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('last_synced_at')->label('Last synchronized')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never'),
                TextEntry::make('last_error')->label('Last error')->placeholder('No errors')->columnSpanFull(),
                KeyValueEntry::make('configuration')->label('Non-secret configuration')->columnSpanFull()->placeholder('No configuration'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => IntegrationConnection::TYPES[$state] ?? InnPresentation::label($state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextColumn::make('last_synced_at')->label('Last sync')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
                TextColumn::make('health')->label('Health')
                    ->state(fn (IntegrationConnection $record): string => filled($record->last_error)
                        ? 'Needs attention'
                        : ($record->status === 'connected' ? 'Healthy' : 'Not tested'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Healthy' => 'success',
                        'Needs attention' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([SelectFilter::make('type')->options(IntegrationConnection::TYPES)])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->using(fn (IntegrationConnection $record, array $data): IntegrationConnection => app(IntegrationConnectionService::class)->configure(
                    $record->name,
                    $record->type,
                    $data['configuration'] ?? [],
                    filled($data['secret_reference'] ?? null) ? $data['secret_reference'] : $record->secret_reference,
                )),
            ])
            ->defaultSort('type')
            ->emptyStateHeading('No integrations configured')
            ->emptyStateDescription('Add a calendar, accounting, payment, signature, email, or webhook adapter without storing provider credentials in Inn.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationConnections::route('/')];
    }
}
