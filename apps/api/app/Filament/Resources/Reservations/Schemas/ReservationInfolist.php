<?php

namespace App\Filament\Resources\Reservations\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Reservation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stay summary')->columns(3)->schema([
                TextEntry::make('confirmation_number')->label('Confirmation')->copyable(),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextEntry::make('property.name')->label('Property'),
                TextEntry::make('primary_guest')
                    ->label('Primary guest')
                    ->state(fn (Reservation $record): string => $record->primaryGuest
                        ? trim("{$record->primaryGuest->first_name} {$record->primaryGuest->last_name}")
                        : 'Unassigned'),
                TextEntry::make('starts_at')->label('Arrival')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('ends_at')->label('Departure')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone()),
                TextEntry::make('party')->state(fn (Reservation $record): string => "{$record->adults} adults · {$record->children} children"),
                TextEntry::make('source')->placeholder('Direct'),
                TextEntry::make('notes')->placeholder('No stay notes')->columnSpanFull(),
            ]),
            Section::make('Folio summary')->columns(3)->schema([
                TextEntry::make('subtotal_minor')->label('Subtotal')->money(fn (Reservation $record): string => $record->currency, divideBy: 100),
                TextEntry::make('tax_minor')->label('Tax')->money(fn (Reservation $record): string => $record->currency, divideBy: 100),
                TextEntry::make('total_minor')->label('Total')->money(fn (Reservation $record): string => $record->currency, divideBy: 100)->weight('bold'),
            ]),
        ]);
    }
}
