<?php

namespace App\Filament\Resources\Deposits;

use App\Models\Deposit;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

final class DepositWorkflowActions
{
    public static function waive(): Action
    {
        return Action::make('waive')
            ->label('Waive deposit')
            ->icon('heroicon-o-hand-raised')
            ->color('warning')
            ->authorize('waive')
            ->schema([
                Textarea::make('reason')->required()->maxLength(5000)->rows(3),
            ])
            ->requiresConfirmation()
            ->visible(fn (Deposit $record): bool => DepositResource::canWaive($record))
            ->action(function (Deposit $record, array $data): void {
                app(DepositService::class)->waive($record, $data['reason'], auth()->id());
                Notification::make()->success()->title('Deposit waived with an audit reason')->send();
            });
    }
}
