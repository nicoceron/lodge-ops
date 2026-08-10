<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Guest experience')
                ->description('Define an activity once, then schedule occurrences on the master calendar.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->relationship('property', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->rows(4)
                        ->columnSpanFull(),
                    TextInput::make('default_duration_minutes')
                        ->label('Default duration')
                        ->suffix('minutes')
                        ->required()
                        ->integer()
                        ->minValue(15)
                        ->default(60),
                    TextInput::make('capacity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                    TextInput::make('price_minor')
                        ->label('Price (minor units)')
                        ->helperText('For USD, enter cents. For COP, enter whole pesos.')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->default(0),
                    TextInput::make('currency')
                        ->required()
                        ->length(3),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                ]),
        ]);
    }
}
