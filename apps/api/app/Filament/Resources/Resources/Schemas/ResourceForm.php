<?php

namespace App\Filament\Resources\Resources\Schemas;

use App\Enums\ResourceType;
use App\Filament\Support\LodgeOpsPresentation;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bookable resource')
                ->description('Rooms, people, transport and equipment share one availability engine.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->relationship('property', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('type')
                        ->options(LodgeOpsPresentation::enumOptions(ResourceType::cases()))
                        ->native(false)
                        ->required(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('code')
                        ->required()
                        ->maxLength(40)
                        ->alphaDash()
                        ->scopedUnique(ignoreRecord: true),
                    TextInput::make('capacity')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->default(1),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    KeyValue::make('attributes')
                        ->keyLabel('Attribute')
                        ->valueLabel('Value')
                        ->addActionLabel('Add attribute')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
