<?php

namespace App\Filament\Resources\Properties\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property details')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('code')->badge()->color('gray'),
                TextEntry::make('timezone')->icon('heroicon-m-clock'),
                IconEntry::make('is_active')->label('Active')->boolean(),
                TextEntry::make('address')->placeholder('No address recorded')->columnSpanFull(),
                TextEntry::make('updated_at')->label('Last updated')->dateTime()->columnSpanFull(),
            ]),
        ]);
    }
}
