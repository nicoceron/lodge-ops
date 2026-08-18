<?php

namespace App\Filament\Resources\Reservations\RelationManagers;

use App\Filament\Support\InnPresentation;
use App\Models\FolioLine;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FolioLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'folioLines';

    protected static ?string $title = 'Folio';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('posted_at')->label('Posted')
                ->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
            TextColumn::make('type')->badge()->formatStateUsing(InnPresentation::label(...)),
            TextColumn::make('description')->wrap(),
            TextColumn::make('net_amount_minor')->label('Net')
                ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100),
            TextColumn::make('tax_amount_minor')->label('Tax')
                ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100),
            TextColumn::make('gross_amount_minor')->label('Gross')
                ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100),
        ])->defaultSort('posted_at', 'desc');
    }
}
