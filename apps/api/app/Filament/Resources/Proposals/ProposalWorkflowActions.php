<?php

namespace App\Filament\Resources\Proposals;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Services\ProposalService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

final class ProposalWorkflowActions
{
    /** @return array<Action> */
    public static function make(): array
    {
        return [
            Action::make('send')
                ->label('Send proposal')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->authorize('send')
                ->requiresConfirmation()
                ->modalDescription('Sending freezes this version and its guest-facing pricing snapshot. Future changes require a new revision.')
                ->visible(fn (Proposal $record): bool => ProposalResource::canRunWorkflow($record) && $record->status === ProposalStatus::Draft)
                ->action(function (Proposal $record): void {
                    app(ProposalService::class)->send($record);
                    Notification::make()->success()->title('Proposal sent and snapshot frozen')->send();
                }),
            Action::make('revise')
                ->label('Create revision')
                ->icon('heroicon-o-document-duplicate')
                ->authorize('update')
                ->visible(fn (Proposal $record): bool => ProposalResource::canRunWorkflow($record)
                    && ! in_array($record->status, [ProposalStatus::Draft, ProposalStatus::Accepted], true))
                ->action(function (Proposal $record): void {
                    app(ProposalService::class)->revise($record, auth()->id());
                    Notification::make()->success()->title('Editable proposal revision created')->send();
                }),
            Action::make('convert')
                ->label('Convert to reservation')
                ->icon('heroicon-o-calendar-days')
                ->color('success')
                ->authorize('convert')
                ->requiresConfirmation()
                ->modalDescription('This accepts the immutable proposal and creates a draft reservation. Inventory remains uncommitted until reservation confirmation.')
                ->visible(fn (Proposal $record): bool => ProposalResource::canRunWorkflow($record) && $record->status === ProposalStatus::Sent)
                ->action(function (Proposal $record): void {
                    app(ProposalService::class)->convertToReservation($record);
                    Notification::make()->success()->title('Draft reservation created')->send();
                }),
        ];
    }
}
