<?php

namespace App\Filament\Resources\Guests\Schemas;

use App\Models\Guest;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class GuestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Guest profile')->columns(2)->schema([
                TextEntry::make('name')
                    ->label('Guest')
                    ->state(fn (Guest $record): string => trim("{$record->first_name} {$record->last_name}")),
                TextEntry::make('language')->badge()->formatStateUsing(fn (?string $state): string => strtoupper($state ?: '—')),
                TextEntry::make('email')->icon('heroicon-m-envelope')->placeholder('Not provided')->copyable(),
                TextEntry::make('phone')->icon('heroicon-m-phone')->placeholder('Not provided'),
                TextEntry::make('document_type')->label('Document type')->placeholder('Not recorded'),
                TextEntry::make('document_number')->label('Document number')->placeholder('Not recorded'),
                IconEntry::make('marketing_consent')->label('Marketing consent')->boolean(),
                KeyValueEntry::make('preferences')->placeholder('No preferences recorded')->columnSpanFull(),
            ]),
        ]);
    }
}
