<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Payment;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable payment record')
                ->description('Corrections happen through refunds or reversals; captured records are never edited in place.')
                ->columns(2)
                ->schema([
                    TextEntry::make('amount_minor')->label('Amount')->money(fn (Payment $record): string => $record->currency, divideBy: 100)->weight('bold'),
                    TextEntry::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                    TextEntry::make('reservation.confirmation_number')->label('Reservation'),
                    TextEntry::make('method')->formatStateUsing(LodgeOpsPresentation::label(...)),
                    TextEntry::make('provider')->placeholder('Manual'),
                    TextEntry::make('provider_reference')->label('Provider reference')->copyable()->placeholder('Not provided'),
                    TextEntry::make('processed_at')->label('Processed')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('Pending'),
                    TextEntry::make('id')->label('Internal ID')->copyable(),
                    KeyValueEntry::make('metadata')->placeholder('No provider metadata')->columnSpanFull(),
                ]),
        ]);
    }
}
