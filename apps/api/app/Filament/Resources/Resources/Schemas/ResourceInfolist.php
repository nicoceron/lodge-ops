<?php

namespace App\Filament\Resources\Resources\Schemas;

use App\Models\Resource;
use Filament\Infolists\Components\IconEntry;
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
                TextEntry::make('user.name')->label('Linked staff')->placeholder('Not linked'),
                TextEntry::make('category.kind')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) && method_exists($state, 'singular') ? $state->singular() : (string) $state)
                    ->color(fn (Resource $record): string => $record->category->kind->color()),
                TextEntry::make('category.name')->label('Category')->badge()->color('info'),
                TextEntry::make('capacity')->numeric(),
                TextEntry::make('housekeeping_status')
                    ->label('Housekeeping')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? 'Not tracked')
                    ->color(fn ($state): string => $state?->color() ?? 'gray'),
                TextEntry::make('housekeeping_updated_at')->label('Housekeeping updated')->dateTime()->placeholder('Never'),
                IconEntry::make('is_buyout')->label('Property buyout')->boolean(),
                IconEntry::make('is_active')->label('Active')->boolean(),
                TextEntry::make('attributes.floor')->label('Floor / location')->placeholder('—'),
                TextEntry::make('attributes.specialties')->label('Specialties')->badge()->placeholder('None'),
                TextEntry::make('attributes.capabilities')->label('Capabilities')->badge()->placeholder('None'),
                TextEntry::make('attributes.languages')->label('Languages')->badge()->placeholder('None'),
            ]),
        ]);
    }
}
