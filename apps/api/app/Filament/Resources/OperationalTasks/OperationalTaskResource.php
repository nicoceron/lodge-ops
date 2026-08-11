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
use App\Models\User;
use App\Services\OperationalTaskAccess;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $membership = app(TenantContext::class)->membership();
        $user = auth()->user();

        if ($membership?->role === null || ! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return app(OperationalTaskAccess::class)->scope($query, $user, $membership->role);
    }

    public static function canView(Model $record): bool
    {
        return parent::canView($record) && self::canAccessTask($record);
    }

    public static function canCreate(): bool
    {
        return parent::canCreate()
            && app(TenantContext::class)->membership()?->role?->canScheduleOperations() === true;
    }

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && self::canAccessTask($record);
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record)
            && app(TenantContext::class)->membership()?->role?->canScheduleOperations() === true;
    }

    public static function canDeleteAny(): bool
    {
        return parent::canDeleteAny()
            && app(TenantContext::class)->membership()?->role?->canScheduleOperations() === true;
    }

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

    private static function canAccessTask(Model $record): bool
    {
        $membership = app(TenantContext::class)->membership();
        $user = auth()->user();

        return $record instanceof OperationalTask
            && $membership?->role !== null
            && $user instanceof User
            && app(OperationalTaskAccess::class)->allows($user, $record, $membership->role);
    }
}
