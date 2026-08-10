<?php

namespace App\Filament\Resources\FolioLines;

use App\Filament\Resources\FolioLines\Pages\ListFolioLines;
use App\Filament\Resources\FolioLines\Pages\ViewFolioLine;
use App\Filament\Resources\FolioLines\Schemas\FolioLineForm;
use App\Filament\Resources\FolioLines\Schemas\FolioLineInfolist;
use App\Filament\Resources\FolioLines\Tables\FolioLinesTable;
use App\Filament\Resources\TenantResource;
use App\Models\FolioLine;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FolioLineResource extends TenantResource
{
    protected static ?string $model = FolioLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 30;

    protected static bool $canCreateRecords = false;

    protected static bool $canEditRecords = false;

    protected static bool $canDeleteRecords = false;

    protected static ?string $viewCapability = 'canManageMoney';

    protected static string $writeCapability = 'canManageMoney';

    public static function canAppend(): bool
    {
        return static::canWrite();
    }

    public static function canReverse(FolioLine $line): bool
    {
        return static::belongsToCurrentTenant($line)
            && static::canWrite()
            && $line->reverses_folio_line_id === null
            && ! $line->reversal()->exists();
    }

    public static function form(Schema $schema): Schema
    {
        return FolioLineForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FolioLineInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FolioLinesTable::configure($table);
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
            'index' => ListFolioLines::route('/'),
            'view' => ViewFolioLine::route('/{record}'),
        ];
    }
}
