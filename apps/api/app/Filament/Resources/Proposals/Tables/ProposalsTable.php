<?php

namespace App\Filament\Resources\Proposals\Tables;

use App\Enums\ProposalStatus;
use App\Filament\Resources\Proposals\ProposalWorkflowActions;
use App\Filament\Support\LodgeOpsPresentation;
use App\Models\Proposal;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProposalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->copyable()->sortable()
                    ->description(fn (Proposal $record): string => "Version {$record->version}"),
                TextColumn::make('primaryGuest.first_name')->label('Guest')
                    ->formatStateUsing(fn ($state, Proposal $record): string => $record->primaryGuest
                        ? trim("{$record->primaryGuest->first_name} {$record->primaryGuest->last_name}")
                        : 'Unassigned')
                    ->searchable(['first_name', 'last_name', 'email']),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(LodgeOpsPresentation::label(...))
                    ->color(fn ($state): string => LodgeOpsPresentation::statusColor($state)),
                TextColumn::make('starts_at')->label('Arrival')->date('M j, Y', timezone: LodgeOpsPresentation::timezone())->sortable(),
                TextColumn::make('total_minor')->label('Total')
                    ->money(fn (Proposal $record): string => $record->currency, divideBy: 100)->sortable(),
                TextColumn::make('expires_at')->label('Valid until')->date('M j, Y', timezone: LodgeOpsPresentation::timezone())->placeholder('No expiry')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(LodgeOpsPresentation::enumOptions(ProposalStatus::cases()))->multiple(),
                SelectFilter::make('property')->relationship('property', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make(ProposalWorkflowActions::make())->label('Workflow')->icon('heroicon-m-ellipsis-horizontal'),
            ])
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->emptyStateHeading('No proposals yet')
            ->emptyStateDescription('Build a versioned quote, freeze it when sent, then convert acceptance into a draft reservation.')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
