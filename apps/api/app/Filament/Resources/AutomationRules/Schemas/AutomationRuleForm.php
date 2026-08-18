<?php

namespace App\Filament\Resources\AutomationRules\Schemas;

use App\Filament\Support\InnPresentation;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Automation')
                ->description('React to operational events with auditable, tenant-owned rules.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Select::make('trigger')
                        ->options(InnPresentation::automationTriggerOptions())
                        ->searchable()
                        ->required(),
                    Toggle::make('is_active')
                        ->default(true)
                        ->required(),
                    KeyValue::make('conditions')
                        ->keyLabel('Condition')
                        ->valueLabel('Expected value')
                        ->addActionLabel('Add condition')
                        ->columnSpanFull(),
                    KeyValue::make('actions')
                        ->keyLabel('Action')
                        ->valueLabel('Configuration')
                        ->addActionLabel('Add action')
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
