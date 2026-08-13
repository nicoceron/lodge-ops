<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Resources\MessageTemplates\Pages\CreateMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Resources\MessageTemplates\Pages\ViewMessageTemplate;
use App\Filament\Resources\MessageTemplates\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MessageTemplateResource extends TenantResource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message template')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('key')->required()->maxLength(80)->alphaDash(),
                Select::make('channel')
                    ->options(['email' => 'Email'])
                    ->helperText('Email is the only configured delivery channel. Add an adapter before enabling another channel.')
                    ->required(),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Template')->columns(2)->schema([
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('key')->copyable(),
                TextEntry::make('channel')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                IconEntry::make('is_active')->label('Active')->boolean(),
                TextEntry::make('versions_count')->label('Versions')->state(fn (MessageTemplate $record): int => $record->versions()->count()),
                TextEntry::make('published_versions_count')->label('Published')->state(fn (MessageTemplate $record): int => $record->versions()->whereNotNull('published_at')->count()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('key')->searchable()->copyable(),
                TextColumn::make('channel')->badge()->formatStateUsing(LodgeOpsPresentation::label(...)),
                TextColumn::make('versions_count')->counts('versions')->label('Versions')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')->options(['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp']),
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('name')
            ->emptyStateHeading('No message templates');
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageTemplates::route('/'),
            'create' => CreateMessageTemplate::route('/create'),
            'view' => ViewMessageTemplate::route('/{record}'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
