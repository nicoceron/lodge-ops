<?php

namespace App\Filament\Resources\Guests\RelationManagers;

use App\Filament\Support\InnPresentation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StaysRelationManager extends RelationManager
{
    protected static string $relationship = 'stays';

    protected static ?string $title = 'Recurring stay history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('confirmation_number')->label('Confirmation')->searchable()->copyable(),
                TextColumn::make('program.name')->label('Package')->placeholder('Simple stay'),
                TextColumn::make('starts_at')->label('Arrival')->dateTime('M j, Y', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('ends_at')->label('Departure')->dateTime('M j, Y', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextColumn::make('total_minor')->label('Value')->money(fn ($record): string => $record->currency, divideBy: 100),
            ])
            ->defaultSort('starts_at', 'desc');
    }
}
