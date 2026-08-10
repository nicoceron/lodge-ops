<?php

namespace App\Filament\Resources\Deposits\Tables;

use App\Enums\DepositStatus;
use App\Filament\Resources\Deposits\DepositWorkflowActions;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Deposit;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DepositsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->copyable(),
                TextColumn::make('status')->badge()->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('amount_minor')->label('Amount')
                    ->money(fn (Deposit $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('due_at')->label('Due')->dateTime('M j, Y · H:i', timezone: LodgeOpsPresentation::timezone())->placeholder('On request')->sortable(),
                TextColumn::make('payment.provider_reference')->label('Payment reference')->placeholder('Not paid')->searchable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(LodgeOpsPresentation::enumOptions(DepositStatus::cases()))->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DepositWorkflowActions::waive(),
            ])
            ->defaultSort('due_at')
            ->striped()
            ->emptyStateHeading('No deposits scheduled')
            ->emptyStateDescription('Create deposit requirements and reconcile them against incoming payments.')
            ->emptyStateIcon('heroicon-o-wallet');
    }
}
