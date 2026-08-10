<?php

namespace App\Filament\Resources\Guests;

use App\Filament\Resources\Guests\Pages\CreateGuest;
use App\Filament\Resources\Guests\Pages\EditGuest;
use App\Filament\Resources\Guests\Pages\ListGuests;
use App\Filament\Resources\Guests\Pages\ViewGuest;
use App\Filament\Resources\Guests\Schemas\GuestForm;
use App\Filament\Resources\Guests\Schemas\GuestInfolist;
use App\Filament\Resources\Guests\Tables\GuestsTable;
use App\Filament\Resources\TenantResource;
use App\Models\Guest;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GuestResource extends TenantResource
{
    protected static ?string $model = Guest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'email';

    protected static string|\UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 20;

    protected static string $writeCapability = 'canManageGuests';

    protected static ?string $viewCapability = 'canManageGuests';

    public static function form(Schema $schema): Schema
    {
        return GuestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return GuestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GuestsTable::configure($table);
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
            'index' => ListGuests::route('/'),
            'create' => CreateGuest::route('/create'),
            'view' => ViewGuest::route('/{record}'),
            'edit' => EditGuest::route('/{record}/edit'),
        ];
    }
}
