<?php

namespace App\Filament\Resources\Deposits\Schemas;

use App\Filament\Support\InnPresentation;
use App\Models\Deposit;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DepositInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Deposit')->columns(2)->schema([
                TextEntry::make('reservation.confirmation_number')->label('Reservation')->copyable(),
                TextEntry::make('status')->badge()->formatStateUsing(InnPresentation::label(...))->color(fn ($state): string => InnPresentation::statusColor($state)),
                TextEntry::make('amount_minor')->label('Amount')->money(fn (Deposit $record): string => $record->currency, divideBy: 100)->weight('bold'),
                TextEntry::make('due_at')->label('Due')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('On request'),
                TextEntry::make('payment.provider_reference')->label('Payment reference')->placeholder('Not paid')->copyable(),
                TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->placeholder('Not paid'),
                TextEntry::make('waiver_reason')->label('Waiver reason')->placeholder('Not waived')->columnSpanFull(),
            ]),
        ]);
    }
}
