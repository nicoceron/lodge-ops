<?php

namespace App\Filament\Resources\IntegrationConnections\RelationManagers;

use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CapabilitiesRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'connectionCapabilities';

    protected static ?string $title = 'Capability health';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return IntegrationConnectionResource::canView($ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('capability')->badge(),
            TextColumn::make('direction')->badge(),
            TextColumn::make('state')->label('Enabled / state')->badge(),
            TextColumn::make('configuration_version')->label('Configuration version'),
            TextColumn::make('last_success_at')->label('Last sync')->dateTime()->placeholder('Never'),
            TextColumn::make('last_error_at')->label('Last error at')->dateTime()->placeholder('Never'),
            TextColumn::make('last_error')->label('Safe error')->wrap()->placeholder('No errors'),
        ])->defaultSort('capability');
    }
}
