<?php

namespace App\Filament\Resources\IntegrationConnections;

use App\Filament\Resources\IntegrationConnections\Pages\ManageIntegrationConnections;
use App\Filament\Resources\IntegrationConnections\Pages\ViewIntegrationConnection;
use App\Filament\Resources\IntegrationConnections\RelationManagers\CapabilitiesRelationManager;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationConnection;
use App\Services\IntegrationConnectionService;
use App\Services\Integrations\EndpointKeyService;
use App\Services\Integrations\IntegrationConfigurationPolicy;
use App\Services\Integrations\IntegrationHealthService;
use App\Services\Integrations\IntegrationReconciliationService;
use App\Services\Integrations\IntegrationRunService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class IntegrationConnectionResource extends TenantResource
{
    protected static ?string $model = IntegrationConnection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $includeTenantWideForProperty = true;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Integration')->description('Credentials stay in an external secret manager; only a reference may be saved here.')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160)->disabledOn('edit'),
                Select::make('type')->options(IntegrationConnection::TYPES)->required()->disabledOn('edit'),
                Select::make('property_id')->label('Property scope')->options(InnPresentation::propertyOptions(...))->placeholder('All properties')->disabledOn('edit'),
                TextInput::make('provider')->maxLength(80)->helperText('Defaults to the integration type when omitted.')->disabledOn('edit'),
                TextInput::make('product')->maxLength(80)->helperText('Defaults to the named provider product for known consumers, otherwise the integration type.')->disabledOn('edit'),
                TextInput::make('external_account_id')->label('External account')->maxLength(160)->helperText('Defaults to the connection name for legacy consumers.')->disabledOn('edit'),
                TextInput::make('provider_application_id')->label('Provider application ID')->maxLength(160)->helperText('Required canonical application identity for Mercado Pago Orders.')->disabledOn('edit'),
                Select::make('environment')->options(['sandbox' => 'Sandbox', 'production' => 'Production', 'test' => 'Test'])->placeholder('Sandbox')->disabledOn('edit'),
                TagsInput::make('capabilities')->helperText('Capability-specific contracts only, for example reservations.import or webhook.outbound.')->columnSpanFull(),
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
                TextEntry::make('provider'),
                TextEntry::make('product'),
                TextEntry::make('external_account_id')->label('External account'),
                TextEntry::make('provider_application_id')->label('Provider application ID')->placeholder('Not applicable'),
                TextEntry::make('environment')->badge(),
                TextEntry::make('property.name')->label('Property scope')->placeholder('All properties'),
                TextEntry::make('status')->badge()->formatStateUsing(fn (string $state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('last_synced_at')->label('Last synchronized')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never'),
                TextEntry::make('last_error')->label('Last error')->placeholder('No errors')->columnSpanFull(),
                TextEntry::make('health_status')->label('Health')->badge(),
                TextEntry::make('lag_seconds')->label('Lag seconds')->placeholder('Unknown'),
                KeyValueEntry::make('configuration')->label('Non-secret configuration')
                    ->state(fn (IntegrationConnection $record): array => app(IntegrationConfigurationPolicy::class)->publicView($record->configuration))
                    ->columnSpanFull()->placeholder('No configuration'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state): string => IntegrationConnection::TYPES[$state] ?? InnPresentation::label($state)),
                TextColumn::make('provider')->badge(),
                TextColumn::make('product'),
                TextColumn::make('environment')->badge(),
                TextColumn::make('configuration_summary')->label('Non-secret configuration')
                    ->state(function (IntegrationConnection $record): string {
                        if ($record->configuration === []) {
                            return 'None';
                        }

                        return collect(app(IntegrationConfigurationPolicy::class)->publicView($record->configuration))
                            ->map(fn (mixed $value, string $key): string => $key.': '.(is_scalar($value) ? (string) $value : '[configured]'))
                            ->implode(', ');
                    })->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state): string => InnPresentation::label($state))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextColumn::make('last_synced_at')->label('Last sync')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Never')->sortable(),
                TextColumn::make('health_status')->label('Health')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'healthy' => 'Healthy',
                        'degraded' => 'Degraded',
                        'unhealthy' => 'Unhealthy',
                        default => 'Not tested',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unhealthy' => 'danger',
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
                    $record->property_id,
                    $record->provider,
                    $record->product,
                    $record->external_account_id,
                    $record->environment,
                    $data['capabilities'] ?? $record->capabilities ?? [],
                    $record->provider_application_id,
                )),
                Action::make('test')->authorize('update')->form([
                    Select::make('capability')->options(fn (IntegrationConnection $record): array => collect($record->capabilities ?? [])->mapWithKeys(fn (string $value): array => [$value => $value])->all())->required(),
                ])->action(function (IntegrationConnection $record, array $data): void {
                    $result = app(IntegrationHealthService::class)->test($record, $data['capability']);
                    Notification::make()->title($result->healthy ? 'Connection test passed' : 'Connection test needs attention')->body($result->safeMessage)->color($result->healthy ? 'success' : 'danger')->send();
                }),
                Action::make('enable')->authorize('update')->requiresConfirmation()->visible(fn (IntegrationConnection $record): bool => ! $record->is_enabled && $record->revoked_at === null)
                    ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                    ->action(fn (IntegrationConnection $record, array $data) => app(IntegrationConnectionService::class)->enable($record, auth()->id(), $data['reason'])),
                Action::make('disable')->authorize('update')->requiresConfirmation()->color('warning')->visible(fn (IntegrationConnection $record): bool => $record->is_enabled)
                    ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                    ->action(fn (IntegrationConnection $record, array $data) => app(IntegrationConnectionService::class)->disable($record, auth()->id(), $data['reason'])),
                Action::make('startRun')->label('Start run')->authorize('update')->requiresConfirmation()->visible(fn (IntegrationConnection $record): bool => $record->is_enabled)
                    ->form([
                        Select::make('capability')->options(fn (IntegrationConnection $record): array => collect($record->capabilities ?? [])->intersect(IntegrationRunService::CAPABILITIES)->mapWithKeys(fn (string $value): array => [$value => $value])->all())->required(),
                    ])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        app(IntegrationRunService::class)->start($record, $data['capability'], $record->property_id, 'manual', 'filament:'.Str::uuid(), auth()->id());
                        Notification::make()->title('Integration run queued')->success()->send();
                    }),
                Action::make('reconcile')->authorize('update')->requiresConfirmation()
                    ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        $result = app(IntegrationReconciliationService::class)->reconcile($record, auth()->id(), $data['reason']);
                        Notification::make()->title('Reconciliation scan completed')->body($result['reconciliations'].' open reconciliation item(s).')->success()->send();
                    }),
                Action::make('rotateEndpoint')->label('Rotate endpoint key')->authorize('update')->requiresConfirmation()
                    ->form([TextInput::make('overlap_minutes')->integer()->minValue(0)->maxValue(1440)->default(15)->required(), Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        $issued = app(EndpointKeyService::class)->rotate($record, (int) $data['overlap_minutes'], auth()->id(), $data['reason']);
                        Notification::make()->title('Endpoint key issued once')->body($issued['key'])->persistent()->warning()->send();
                    }),
                Action::make('revoke')->authorize('update')->requiresConfirmation()->color('danger')->visible(fn (IntegrationConnection $record): bool => $record->revoked_at === null)
                    ->form([Textarea::make('reason')->required()->minLength(3)->maxLength(500)])
                    ->action(function (IntegrationConnection $record, array $data): void {
                        app(EndpointKeyService::class)->revokeAll($record, auth()->id(), $data['reason']);
                        app(IntegrationConnectionService::class)->revoke($record, auth()->id(), $data['reason']);
                    }),
            ])
            ->defaultSort('type')
            ->emptyStateHeading('No integrations configured')
            ->emptyStateDescription('Add a calendar, accounting, payment, signature, email, or webhook adapter without storing provider credentials in Inn.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageIntegrationConnections::route('/'),
            'view' => ViewIntegrationConnection::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [CapabilitiesRelationManager::class];
    }
}
