<?php

namespace App\Filament\Resources\Programs;

use App\Filament\Resources\Programs\Pages\CreateProgram;
use App\Filament\Resources\Programs\Pages\EditProgram;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Programs\Schemas\ProgramForm;
use App\Filament\Resources\Programs\Schemas\ProgramInfolist;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Program;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProgramResource extends TenantResource
{
    protected static ?string $model = Program::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 30;

    protected static string $writeCapability = 'canManageConfiguration';

    protected static ?string $viewCapability = 'canManageConfiguration';

    public static function form(Schema $schema): Schema
    {
        return ProgramForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProgramInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProgramsTable::configure($table);
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
            'index' => ListPrograms::route('/'),
            'create' => CreateProgram::route('/create'),
            'view' => ViewProgram::route('/{record}'),
            'edit' => EditProgram::route('/{record}/edit'),
        ];
    }
}
