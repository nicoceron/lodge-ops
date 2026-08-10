<?php

namespace App\Filament\Resources\AutomationRules\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationRuleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Automation rule')->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('trigger')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color('info'),
                IconEntry::make('is_active')->label('Enabled')->boolean(),
                TextEntry::make('last_ran_at')->label('Last run')->since()->placeholder('Never'),
                KeyValueEntry::make('conditions')->placeholder('No conditions')->columnSpanFull(),
                KeyValueEntry::make('actions')->columnSpanFull(),
            ]),
        ]);
    }
}
