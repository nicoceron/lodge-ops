<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepositForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deposit requirement')->columns(2)->schema([
                Select::make('reservation_id')
                    ->relationship('reservation', 'confirmation_number')
                    ->searchable()
                    ->preload()
                    ->disabledOn('edit')
                    ->dehydrated()
                    ->required(),
                TextInput::make('amount_minor')
                    ->label('Amount (minor units)')
                    ->integer()
                    ->minValue(1)
                    ->required(),
                DateTimePicker::make('due_at')
                    ->timezone(LodgeOpsPresentation::timezone())
                    ->seconds(false),
            ]),
        ]);
    }
}
