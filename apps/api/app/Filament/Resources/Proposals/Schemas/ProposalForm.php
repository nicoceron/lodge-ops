<?php

namespace App\Filament\Resources\Proposals\Schemas;

use App\Filament\Support\InnPresentation;
use App\Models\Program;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProposalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proposed stay')
                ->description('Build a quote without consuming inventory. Availability is committed only when the resulting reservation is confirmed.')
                ->columns(2)
                ->schema([
                    Select::make('property_id')
                        ->options(InnPresentation::propertyOptions(...))
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('primary_guest_id')
                        ->label('Primary guest')
                        ->relationship('primaryGuest', 'first_name')
                        ->searchable(['first_name', 'last_name', 'email'])
                        ->preload(),
                    Select::make('program_id')
                        ->label('Program')
                        ->options(fn (Get $get): array => Program::query()
                            ->when($get('property_id'), fn ($query, string $propertyId) => $query->where('property_id', $propertyId))
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload(),
                    DateTimePicker::make('starts_at')
                        ->label('Arrival')
                        ->timezone(InnPresentation::timezone())
                        ->seconds(false)
                        ->required(),
                    DateTimePicker::make('ends_at')
                        ->label('Departure')
                        ->timezone(InnPresentation::timezone())
                        ->seconds(false)
                        ->after('starts_at')
                        ->required(),
                    TextInput::make('adults')->integer()->minValue(1)->default(2)->required(),
                    TextInput::make('children')->integer()->minValue(0)->default(0)->required(),
                    TextInput::make('currency')
                        ->default(fn (): string => app(TenantContext::class)->tenant()->currency)
                        ->length(3)
                        ->required(),
                    DateTimePicker::make('expires_at')
                        ->label('Valid until')
                        ->timezone(InnPresentation::timezone())
                        ->seconds(false)
                        ->after('now'),
                    TextInput::make('title')->default('Lodge stay proposal')->maxLength(255)->columnSpanFull(),
                    Textarea::make('notes')->rows(3)->columnSpanFull(),
                ]),
            Section::make('Pricing')
                ->description('Line amounts are recalculated server-side with integer minor-unit arithmetic.')
                ->schema([
                    Repeater::make('lines')
                        ->schema([
                            TextInput::make('description')->required()->maxLength(500)->columnSpan(2),
                            TextInput::make('quantity')->numeric()->step(0.001)->minValue(0.001)->default(1)->required(),
                            TextInput::make('unit_amount_minor')->label('Unit amount (minor units)')->integer()->required(),
                        ])
                        ->columns(4)
                        ->defaultItems(1)
                        ->minItems(1)
                        ->reorderable()
                        ->addActionLabel('Add price line')
                        ->required(),
                    TextInput::make('tax_minor')
                        ->label('Tax (minor units)')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                ]),
        ]);
    }
}
