<?php

namespace App\Filament\Resources\Programs\Schemas;

use App\Enums\MembershipRole;
use App\Enums\ResourceType;
use App\Filament\Support\LodgeOpsPresentation;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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
                    ColorPicker::make('display_color')
                        ->label('Calendar color')
                        ->default('#2563EB')
                        ->required(),
                    Toggle::make('requires_accommodation')
                        ->label('Requires a full-stay room')
                        ->helperText('Confirmation is blocked until a room covers arrival through departure.')
                        ->default(false),
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
            Section::make('Resource requirements')
                ->description('Confirmation and holds enforce these quantities, guest ratios, skills and languages.')
                ->schema([
                    Repeater::make('requirements')
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(0)
                        ->schema([
                            Select::make('resource_type')
                                ->options(LodgeOpsPresentation::enumOptions(ResourceType::cases()))
                                ->required(),
                            TextInput::make('minimum_quantity')
                                ->label('Minimum quantity')
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required(),
                            TextInput::make('guests_per_resource')
                                ->label('Guests per resource')
                                ->helperText('Example: 4 means one guide is required for every four guests.')
                                ->integer()
                                ->minValue(1),
                            TextInput::make('sort_order')->integer()->minValue(0)->default(0),
                            TagsInput::make('capabilities')
                                ->helperText('All listed skills must be present on the assigned resource.'),
                            TagsInput::make('languages')
                                ->helperText('All listed languages must be present on the assigned resource.'),
                        ]),
                ]),
            Section::make('Confirmation checklist')
                ->description('Active templates become reservation tasks once, when the reservation is confirmed.')
                ->schema([
                    Repeater::make('taskTemplates')
                        ->relationship()
                        ->columns(2)
                        ->defaultItems(0)
                        ->schema([
                            TextInput::make('title')->required()->maxLength(255),
                            Select::make('assignee_role')
                                ->label('Default role')
                                ->options(LodgeOpsPresentation::enumOptions(MembershipRole::cases()))
                                ->searchable(),
                            Textarea::make('description')->rows(3)->columnSpanFull(),
                            Select::make('priority')
                                ->options(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'])
                                ->default('normal')
                                ->required(),
                            TextInput::make('due_offset_minutes')
                                ->label('Due offset from arrival')
                                ->suffix('minutes')
                                ->helperText('Use a negative number for work due before arrival.')
                                ->integer()
                                ->default(0)
                                ->required(),
                            TextInput::make('sort_order')->integer()->minValue(0)->default(0),
                            Toggle::make('is_active')->default(true)->required(),
                        ]),
                ]),
        ]);
    }
}
