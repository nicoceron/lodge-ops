<?php

namespace App\Filament\Resources\IntegrationMappings;

use App\Filament\Resources\IntegrationMappings\Pages\ManageIntegrationMappings;
use App\Filament\Resources\TenantResource;
use App\Filament\Support\InnPresentation;
use App\Models\IntegrationMapping;
use BackedEnum;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationMappingResource extends TenantResource
{
    protected static ?string $model = IntegrationMapping::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Templates & Integrations';

    protected static ?string $viewCapability = 'canManageConfiguration';

    protected static string $writeCapability = 'canManageConfiguration';

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('integration_connection_id')->relationship('connection', 'name')->required(),
            Select::make('property_id')->options(InnPresentation::propertyOptions(...))->placeholder('All properties'),
            TextInput::make('capability')->required()->maxLength(80), Select::make('direction')->options(['inbound' => 'Inbound', 'outbound' => 'Outbound'])->required(),
            TextInput::make('local_entity_type')->required(), TextInput::make('local_key')->required(),
            TextInput::make('external_entity_type')->required(), TextInput::make('external_key')->required(),
            TextInput::make('transform_version')->integer()->minValue(1)->required()->default(1), KeyValue::make('safe_facts'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('connection.name'), TextColumn::make('capability')->badge(), TextColumn::make('direction')->badge(),
            TextColumn::make('local_entity_type'), TextColumn::make('local_key'), TextColumn::make('external_entity_type'),
            TextColumn::make('external_key'), TextColumn::make('transform_version')->label('Transform'), TextColumn::make('conflict_state')->badge(),
        ])->defaultSort('valid_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageIntegrationMappings::route('/')];
    }
}
