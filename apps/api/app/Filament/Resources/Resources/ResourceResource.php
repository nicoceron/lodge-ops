<?php

namespace App\Filament\Resources\Resources;

use App\Filament\Resources\Resources\Pages\CreateResource;
use App\Filament\Resources\Resources\Pages\EditResource;
use App\Filament\Resources\Resources\Pages\ListResources;
use App\Filament\Resources\Resources\Pages\ViewResource;
use App\Filament\Resources\Resources\RelationManagers\BlocksRelationManager;
use App\Filament\Resources\Resources\Schemas\ResourceForm;
use App\Filament\Resources\Resources\Schemas\ResourceInfolist;
use App\Filament\Resources\Resources\Tables\ResourcesTable;
use App\Filament\Resources\TenantResource;
use App\Models\Resource as ResourceModel;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ResourceResource extends TenantResource
{
    protected static ?string $model = ResourceModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 20;

    protected static string $writeCapability = 'canManageConfiguration';

    protected static ?string $viewCapability = 'canViewResources';

    public static function form(Schema $schema): Schema
    {
        return ResourceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResourceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResourcesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            BlocksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResources::route('/'),
            'create' => CreateResource::route('/create'),
            'view' => ViewResource::route('/{record}'),
            'edit' => EditResource::route('/{record}/edit'),
        ];
    }
}
