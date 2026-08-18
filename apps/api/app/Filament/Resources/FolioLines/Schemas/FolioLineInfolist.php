<?php

namespace App\Filament\Resources\FolioLines\Schemas;

use App\Filament\Support\InnPresentation;
use App\Models\FolioLine;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FolioLineInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Append-only ledger entry')->columns(2)->schema([
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->copyable(),
                TextEntry::make('type')->badge()->formatStateUsing(InnPresentation::label(...)),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('net_amount_minor')->label('Net')->money(fn (FolioLine $record): string => $record->currency, divideBy: 100),
                TextEntry::make('tax_amount_minor')->label('Tax')->money(fn (FolioLine $record): string => $record->currency, divideBy: 100),
                TextEntry::make('gross_amount_minor')->label('Gross')->money(fn (FolioLine $record): string => $record->currency, divideBy: 100)->weight('bold'),
                TextEntry::make('posted_at')->label('Posted')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone()),
                TextEntry::make('creator.name')->label('Posted by')->placeholder('System'),
                TextEntry::make('reverses.id')->label('Reverses entry')->placeholder('Original entry')->copyable(),
                KeyValueEntry::make('metadata')->placeholder('No metadata')->columnSpanFull(),
            ]),
        ]);
    }
}
