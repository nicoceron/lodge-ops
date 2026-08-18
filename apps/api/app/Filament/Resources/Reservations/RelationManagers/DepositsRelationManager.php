<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use App\Models\Deposit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'deposits';

    protected static ?string $title = 'Deposits';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('amount_minor')->label('Amount')
                ->money(fn (Deposit $record): string => $record->currency, divideBy: 100),
            TextColumn::make('due_at')->label('Due')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('On request'),
            TextColumn::make('payment.provider_reference')->label('Payment reference')->placeholder('Not paid'),
        ])->defaultSort('due_at');
    }
}
