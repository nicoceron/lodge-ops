<?php

namespace App\Filament\Resources\Programs\Schemas;

use App\Models\Program;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProgramInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Program details')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('description')->placeholder('No description')->columnSpanFull(),
                TextEntry::make('default_duration_minutes')->label('Duration')->suffix(' minutes'),
                TextEntry::make('capacity')->numeric(),
                TextEntry::make('price_minor')->label('Price')->money(fn (Program $record): string => $record->currency, divideBy: 100),
                IconEntry::make('is_active')->label('Active')->boolean(),
            ]),
        ]);
    }
}
