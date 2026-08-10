<?php

namespace App\Filament\Resources\OperationalTasks;

use App\Filament\Resources\OperationalTasks\Pages\CreateOperationalTask;
use App\Filament\Resources\OperationalTasks\Pages\EditOperationalTask;
use App\Filament\Resources\OperationalTasks\Pages\ListOperationalTasks;
use App\Filament\Resources\OperationalTasks\Pages\ViewOperationalTask;
use App\Filament\Resources\OperationalTasks\Schemas\OperationalTaskForm;
use App\Filament\Resources\OperationalTasks\Schemas\OperationalTaskInfolist;
use App\Filament\Resources\OperationalTasks\Tables\OperationalTasksTable;
use App\Filament\Resources\TenantResource;
use App\Models\OperationalTask;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OperationalTaskResource extends TenantResource
{
    protected static ?string $model = OperationalTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static string $writeCapability = 'canManageOperations';

    protected static string $deleteCapability = 'canManageOperations';

    protected static ?string $viewCapability = 'canManageOperations';

    public static function form(Schema $schema): Schema
    {
        return OperationalTaskForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OperationalTaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OperationalTasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOperationalTasks::route('/'),
            'create' => CreateOperationalTask::route('/create'),
            'view' => ViewOperationalTask::route('/{record}'),
            'edit' => EditOperationalTask::route('/{record}/edit'),
        ];
    }
}
