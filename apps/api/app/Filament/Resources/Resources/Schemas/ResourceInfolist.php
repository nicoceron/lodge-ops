<?php

namespace App\Filament\Resources\Resources\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resource details')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('code')->badge()->color('gray'),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('type')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color('info'),
                TextEntry::make('capacity')->numeric(),
                IconEntry::make('is_active')->label('Active')->boolean(),
                KeyValueEntry::make('attributes')->placeholder('No attributes recorded')->columnSpanFull(),
            ]),
        ]);
    }
}
