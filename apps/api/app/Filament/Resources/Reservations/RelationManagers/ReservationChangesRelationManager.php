<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use App\Models\ReservationChange;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReservationChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'changes';

    protected static ?string $title = 'Change ledger';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('When')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('type')->state(fn (ReservationChange $record): string => InnPresentation::label($record->type)),
            TextColumn::make('status')->state(fn (ReservationChange $record): string => InnPresentation::label($record->status)),
            TextColumn::make('amount_minor')->label('Amount / delta')
                ->money(fn (ReservationChange $record): string => $record->currency ?? 'USD', divideBy: 100)->placeholder('—'),
            TextColumn::make('actor.name')->label('By')->placeholder('System'),
            TextColumn::make('reference')->placeholder('—')->copyable(),
            TextColumn::make('metadata.reason')->label('Reason')->wrap()->placeholder('—'),
        ])->defaultSort('occurred_at', 'desc');
    }
}
