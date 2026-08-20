<?php

namespace App\Filament\Resources\CommunicationProviderConnections;

use App\Filament\Resources\CommunicationProviderConnections\Pages\ManageCommunicationProviderConnections;
use App\Filament\Resources\TenantResource;
use App\Models\CommunicationProviderConnection;
use App\Models\Property;
use App\Models\User;
use App\Services\Communications\CommunicationProviderVerificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunicationProviderConnectionResource extends TenantResource
{
    protected static ?string $model = CommunicationProviderConnection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 29;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property sender and provider identity')->columns(2)->schema([
                Select::make('property_id')->options(fn (): array => Property::query()->pluck('name', 'id')->all())->required(),
                Select::make('provider')->options(['resend' => 'Resend'])->default('resend')->required(),
                TextInput::make('account_id')->required()->maxLength(160),
                TextInput::make('endpoint_key')->label('Opaque webhook endpoint key')->password()->revealable()
                    ->helperText('Shown only while entered. The database stores only its SHA-256 hash.')
                    ->required(fn (string $operation): bool => $operation === 'create')->dehydrated(fn (?string $state): bool => filled($state)),
                TextInput::make('secret_ref')->label('API secret reference')->placeholder('env:RESEND_API_KEY_PRIMARY')->required()->maxLength(190),
                TagsInput::make('webhook_secret_refs')->placeholder('env:RESEND_WEBHOOK_SECRET_PRIMARY')->required(),
                TextInput::make('from_email')->email()->required()->maxLength(254),
                TextInput::make('from_name')->required()->maxLength(160),
                TextInput::make('reply_to_email')->email()->maxLength(254),
                TagsInput::make('allowed_sender_domains')->required(),
                Toggle::make('is_enabled')->helperText('Available only after the provider verification action succeeds.')
                    ->disabled(fn (?CommunicationProviderConnection $record): bool => $record?->verified_at === null),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('property.name'), TextEntry::make('provider')->badge(), TextEntry::make('account_id'),
            TextEntry::make('from_email'), TextEntry::make('reply_to_email')->placeholder('None'),
            TextEntry::make('endpoint_key_hash')->copyable(), TextEntry::make('secret_ref'),
            TextEntry::make('verified_at')->dateTime()->placeholder('Not verified'), TextEntry::make('revoked_at')->dateTime()->placeholder('Active'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('property.name')->searchable(), TextColumn::make('provider')->badge(),
            TextColumn::make('from_email')->searchable(), TextColumn::make('account_id')->limit(24),
            IconColumn::make('is_enabled')->boolean(), TextColumn::make('verified_at')->since()->placeholder('Not verified'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            Action::make('verify_provider')->label('Verify sender')->icon('heroicon-o-shield-check')
                ->requiresConfirmation()
                ->action(function (CommunicationProviderConnection $record): void {
                    app(CommunicationProviderVerificationService::class)
                        ->verify(User::query()->findOrFail(auth()->id()), $record);
                    Notification::make()->success()->title('Provider sender domain verified')->send();
                }),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommunicationProviderConnections::route('/')];
    }
}
