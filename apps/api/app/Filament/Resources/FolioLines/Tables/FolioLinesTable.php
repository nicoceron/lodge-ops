<?php

namespace App\Filament\Resources\FolioLines\Tables;

use App\Enums\FolioLineType;
use App\Filament\Resources\FolioLines\FolioWorkflowActions;
use App\Filament\Support\InnPresentation;
use App\Models\FolioLine;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FolioLinesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('posted_at')->label('Posted')->dateTime('M j, Y · H:i', timezone: InnPresentation::timezone())->sortable(),
                TextColumn::make('reservation.confirmation_number')->label('Reservation')->searchable()->copyable(),
                TextColumn::make('type')->badge()->formatStateUsing(InnPresentation::label(...))
                    ->color(fn ($state): string => match ($state instanceof FolioLineType ? $state : FolioLineType::tryFrom((string) $state)) {
                        FolioLineType::Payment => 'success',
                        FolioLineType::Refund => 'warning',
                        FolioLineType::Charge => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('description')->searchable()->wrap(),
                TextColumn::make('net_amount_minor')->label('Net')
                    ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('tax_amount_minor')->label('Tax')
                    ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100)->toggleable(),
                TextColumn::make('gross_amount_minor')->label('Gross')
                    ->money(fn (FolioLine $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('creator.name')->label('Posted by')->placeholder('System'),
            ])
            ->filters([
                SelectFilter::make('type')->options(InnPresentation::enumOptions(FolioLineType::cases()))->multiple(),
                SelectFilter::make('reservation')->relationship('reservation', 'confirmation_number')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                FolioWorkflowActions::reverse(),
            ])
            ->defaultSort('posted_at', 'desc')
            ->striped()
            ->emptyStateHeading('No folio entries')
            ->emptyStateDescription('Payments, charges, adjustments and balancing reversals appear here as an append-only ledger.')
            ->emptyStateIcon('heroicon-o-receipt-percent');
    }
}
