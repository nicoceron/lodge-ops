<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('processed_at')->label('Processed')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Pending'),
            TextColumn::make('status')->badge()->formatStateUsing(InnPresentation::label(...))
                ->color(fn ($state): string => InnPresentation::statusColor($state)),
            TextColumn::make('amount_minor')->label('Amount')
                ->money(fn (Payment $record): string => $record->currency, divideBy: 100),
            TextColumn::make('method')->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('provider_reference')->label('Reference')->placeholder('—')->copyable(),
        ])->defaultSort('processed_at', 'desc');
    }
}
