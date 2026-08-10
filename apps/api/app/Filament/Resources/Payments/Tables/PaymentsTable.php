<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentWorkflowActions;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Payment;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())
                    ->placeholder('Pending')
                    ->sortable(),
                TextColumn::make('reservation.confirmation_number')
                    ->label('Reservation')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->color(fn ($state): string => LodgeOpsPresentation::statusColor($state))
                    ->sortable(),
                TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->money(fn (Payment $record): string => $record->currency, divideBy: 100)
                    ->sortable(),
                TextColumn::make('method')
                    ->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->searchable(),
                TextColumn::make('provider_reference')
                    ->label('Reference')
                    ->copyable()
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(LodgeOpsPresentation::enumOptions(PaymentStatus::cases()))
                    ->multiple(),
                SelectFilter::make('method')
                    ->options([
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'bank_transfer' => 'Bank transfer',
                        'online' => 'Online',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(PaymentWorkflowActions::forRecord())
                    ->label('Workflow')
                    ->icon('heroicon-m-ellipsis-horizontal'),
            ])
            ->defaultSort('processed_at', 'desc')
            ->striped()
            ->emptyStateHeading('No payments recorded')
            ->emptyStateDescription('Captured payments appear here as immutable financial records.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }
}
