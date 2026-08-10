<?php

namespace App\Filament\Resources\Programs\Schemas;

use App\Models\Program;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
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
                TextEntry::make('display_color')->label('Calendar color')->badge(),
                IconEntry::make('requires_accommodation')->label('Requires accommodation')->boolean(),
                TextEntry::make('default_duration_minutes')->label('Duration')->suffix(' minutes'),
                TextEntry::make('capacity')->numeric(),
                TextEntry::make('price_minor')->label('Price')->money(fn (Program $record): string => $record->currency, divideBy: 100),
                IconEntry::make('is_active')->label('Active')->boolean(),
            ]),
            Section::make('Resource requirements')->schema([
                RepeatableEntry::make('requirements')->schema([
                    TextEntry::make('resource_type')->badge()->formatStateUsing(fn ($state): string => $state->value),
                    TextEntry::make('minimum_quantity')->label('Minimum')->numeric(),
                    TextEntry::make('guests_per_resource')->label('Guests per resource')->placeholder('Fixed quantity'),
                    TextEntry::make('capabilities')->badge()->placeholder('Any capability'),
                    TextEntry::make('languages')->badge()->placeholder('Any language'),
                ])->columns(3),
            ]),
            Section::make('Confirmation checklist')->schema([
                RepeatableEntry::make('taskTemplates')->schema([
                    TextEntry::make('title')->weight('medium'),
                    TextEntry::make('assignee_role')->label('Role')->badge()->placeholder('Unassigned'),
                    TextEntry::make('priority')->badge(),
                    TextEntry::make('due_offset_minutes')->label('Arrival offset')->suffix(' minutes'),
                    IconEntry::make('is_active')->boolean(),
                ])->columns(3),
            ]),
        ]);
    }
}
