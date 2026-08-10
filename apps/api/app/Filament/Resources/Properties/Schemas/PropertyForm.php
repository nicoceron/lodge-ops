<?php

namespace App\Filament\Resources\Properties\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PropertyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Property details')
                ->description('Operational identity and local time settings for this lodge.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->required()
                        ->maxLength(30)
                        ->alphaDash()
                        ->scopedUnique(ignoreRecord: true),
                    TextInput::make('timezone')
                        ->required()
                        ->rules(['timezone'])
                        ->default('UTC')
                        ->placeholder('America/Bogota')
                        ->helperText('IANA timezone used for calendars and operational cutoffs.'),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    Textarea::make('address')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
