<?php

namespace App\Filament\Resources\Guests\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GuestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Guest profile')
                ->description('Contact details and preferences shared across every stay.')
                ->columns(2)
                ->schema([
                    TextInput::make('first_name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('last_name')
                        ->maxLength(120),
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->maxLength(255)
                        ->scopedUnique(ignoreRecord: true),
                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(40),
                    Select::make('language')
                        ->options([
                            'en' => 'English',
                            'es' => 'Español',
                            'fr' => 'Français',
                            'pt' => 'Português',
                        ])
                        ->searchable(),
                    Toggle::make('marketing_consent')
                        ->label('Marketing consent recorded')
                        ->default(false),
                ]),
            Section::make('Identity and service notes')
                ->columns(2)
                ->collapsible()
                ->schema([
                    TextInput::make('document_type')
                        ->maxLength(40),
                    TextInput::make('document_number')
                        ->maxLength(100),
                    KeyValue::make('preferences')
                        ->keyLabel('Preference')
                        ->valueLabel('Details')
                        ->addActionLabel('Add preference')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
