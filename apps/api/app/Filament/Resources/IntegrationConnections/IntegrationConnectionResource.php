<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Filament\Resources\IntegrationConnections\Pages\ManageIntegrationConnections;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
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
                Select::make('type')->options(['email' => 'Email', 'calendar' => 'Calendar', 'accounting' => 'Accounting', 'payment' => 'Payment', 'signature' => 'Signature', 'webhook' => 'Webhook'])->required()->disabledOn('edit'),
                KeyValue::make('configuration')->label('Non-secret configuration')->columnSpanFull(),
                TextInput::make('secret_reference')->label('Secret manager reference')->password()->revealable()->maxLength(500)->helperText('For example: vault://tenant/integration-name')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection health')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('last_synced_at')->label('Last synchronized')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Never'),
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
                TextColumn::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('last_synced_at')->label('Last sync')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Never')->sortable(),
                TextColumn::make('last_error')->label('Health')->formatStateUsing(fn (?string $state): string => blank($state) ? 'Healthy' : 'Needs attention')->badge()->color(fn (?string $state): string => blank($state) ? 'success' : 'danger'),
            ])
            ->filters([SelectFilter::make('type')->options(['email' => 'Email', 'calendar' => 'Calendar', 'accounting' => 'Accounting', 'payment' => 'Payment', 'signature' => 'Signature', 'webhook' => 'Webhook'])])
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
            ->emptyStateHeading('No integrations configured');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationConnections::route('/')];
    }
}
